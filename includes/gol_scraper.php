<?php
declare(strict_types=1);

function import_gol_old_picks(string $url, int $userId): array
{
    validate_gol_url($url);

    $user = find_user_by_id($userId);
    if (!$user || $user['role'] !== 'player') {
        throw new RuntimeException('Choose a valid player account before importing picks.');
    }

    $html = fetch_gol_html($url);
    $rows = parse_gol_pick_rows($html);

    if (!$rows) {
        throw new RuntimeException('No match picks were found on that Gol.bg page.');
    }

    $stats = [
        'parsed' => count($rows),
        'with_picks' => 0,
        'matches_created' => 0,
        'matches_matched' => 0,
        'predictions_created' => 0,
        'predictions_updated' => 0,
        'skipped_without_pick' => 0,
    ];

    foreach ($rows as $row) {
        if ($row['pred1'] === null || $row['pred2'] === null) {
            $stats['skipped_without_pick']++;
            continue;
        }

        $stats['with_picks']++;
        [$match, $created, $reversed] = find_or_create_gol_match($row);

        if ($created) {
            $stats['matches_created']++;
        } else {
            $stats['matches_matched']++;
            update_match_result_from_gol($match, $row, $reversed);
            $match = find_match_by_id((int) $match['id']);
        }

        $pred1 = (int) $row['pred1'];
        $pred2 = (int) $row['pred2'];

        if ($reversed) {
            [$pred1, $pred2] = [$pred2, $pred1];
        }

        $points = prediction_points(
            $pred1,
            $pred2,
            $match['score1'] === null ? null : (int) $match['score1'],
            $match['score2'] === null ? null : (int) $match['score2']
        );

        if (upsert_prediction($userId, (int) $match['id'], $pred1, $pred2, $points)) {
            $stats['predictions_updated']++;
        } else {
            $stats['predictions_created']++;
        }
    }

    recalculate_all_points();

    return $stats;
}

function validate_gol_url(string $url): void
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Enter a valid Gol.bg URL.');
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = (string) parse_url($url, PHP_URL_PATH);

    if (!preg_match('/(^|\.)gol\.bg$/', $host) || !str_contains($path, '/worldcup2026/game/klasirane/')) {
        throw new RuntimeException('The URL must be a Gol.bg World Cup 2026 standings/picks page.');
    }
}

function fetch_gol_html(string $url): string
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 wc2026-predictions-importer/1.0',
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $status >= 400) {
            throw new RuntimeException('Could not fetch Gol.bg page: ' . ($error ?: 'HTTP ' . $status));
        }

        return (string) $body;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'header' => "User-Agent: Mozilla/5.0 wc2026-predictions-importer/1.0\r\n",
        ],
    ]);
    $body = file_get_contents($url, false, $context);

    if ($body === false) {
        throw new RuntimeException('Could not fetch Gol.bg page.');
    }

    return $body;
}

function parse_gol_pick_rows(string $html): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $dateNodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' matches-date ')]");
    $rows = [];

    foreach ($dateNodes as $dateNode) {
        $date = parse_gol_date(clean_gol_text($dateNode->textContent));
        if ($date === null) {
            continue;
        }

        $listNode = next_element_with_class($dateNode, 'matches-list');
        if (!$listNode) {
            continue;
        }

        $matchNodes = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' main ')]", $listNode);
        foreach ($matchNodes as $matchNode) {
            $time = clean_gol_text(first_xpath_text($xpath, $matchNode, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' date ')]"));
            $team1 = clean_team_name(first_xpath_text($xpath, $matchNode, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' team ') and contains(concat(' ', normalize-space(@class), ' '), ' first ')]"));
            $team2 = clean_team_name(first_xpath_text($xpath, $matchNode, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' team ') and contains(concat(' ', normalize-space(@class), ' '), ' second ')]"));
            $result = parse_score(first_xpath_text($xpath, $matchNode, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' result ')]"));
            $pick = parse_score(first_xpath_text($xpath, $matchNode, ".//*[contains(concat(' ', normalize-space(@class), ' '), ' prognosis-form ')]"));

            if ($team1 === '' || $team2 === '') {
                continue;
            }

            $kickoffAtUtc = parse_gol_kickoff_utc($date, $time);
            $isFuture = $kickoffAtUtc !== null && $kickoffAtUtc > now_sql();
            $score1 = !$isFuture && $result ? $result[0] : null;
            $score2 = !$isFuture && $result ? $result[1] : null;

            $rows[] = [
                'date' => $date,
                'time' => $time,
                'kickoff_at_utc' => $kickoffAtUtc,
                'team1' => $team1,
                'team2' => $team2,
                'score1' => $score1,
                'score2' => $score2,
                'status' => $score1 !== null && $score2 !== null ? 'finished' : 'scheduled',
                'pred1' => $pick ? $pick[0] : null,
                'pred2' => $pick ? $pick[1] : null,
            ];
        }
    }

    return $rows;
}

function next_element_with_class(DOMNode $node, string $class): ?DOMElement
{
    for ($sibling = $node->nextSibling; $sibling; $sibling = $sibling->nextSibling) {
        if (!$sibling instanceof DOMElement) {
            continue;
        }

        $classes = ' ' . preg_replace('/\s+/', ' ', $sibling->getAttribute('class')) . ' ';
        if (str_contains($classes, ' ' . $class . ' ')) {
            return $sibling;
        }

        if (str_contains($classes, ' matches-date ')) {
            return null;
        }
    }

    return null;
}

function first_xpath_text(DOMXPath $xpath, DOMNode $context, string $query): string
{
    $nodes = $xpath->query($query, $context);
    if (!$nodes || $nodes->length === 0) {
        return '';
    }

    return $nodes->item(0)?->textContent ?? '';
}

function clean_gol_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

    return trim($text);
}

function clean_team_name(string $text): string
{
    $text = clean_gol_text($text);
    $text = preg_replace('/\bteam image\b/iu', '', $text) ?? $text;

    return trim($text);
}

function parse_gol_date(string $text): ?string
{
    if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/u', $text, $matches)) {
        return null;
    }

    return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
}

function parse_score(string $text): ?array
{
    $text = clean_gol_text($text);
    if (!preg_match('/(\d+|-)\s*:\s*(\d+|-)/u', $text, $matches)) {
        return null;
    }

    if ($matches[1] === '-' || $matches[2] === '-') {
        return null;
    }

    return [(int) $matches[1], (int) $matches[2]];
}

function parse_gol_kickoff_utc(string $date, string $time): ?string
{
    if (!preg_match('/(\d{1,2}):(\d{2})/u', $time, $matches)) {
        return null;
    }

    $local = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
        $date . ' ' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2],
        new DateTimeZone('Europe/Sofia')
    );

    if (!$local) {
        return null;
    }

    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function find_or_create_gol_match(array $row): array
{
    $existing = find_existing_match_for_gol_row($row);
    if ($existing) {
        return [$existing['match'], false, $existing['reversed']];
    }

    $nextNo = (int) db()->query('SELECT COALESCE(MAX(match_no), 0) + 1 FROM wc_matches')->fetchColumn();
    $record = [
        'external_id' => 'gol-' . substr(sha1($row['date'] . '|' . normalize_match_name($row['team1']) . '|' . normalize_match_name($row['team2'])), 0, 16),
        'match_no' => $nextNo,
        'round_name' => 'Gol.bg import',
        'match_date' => $row['date'],
        'match_time' => $row['time'],
        'kickoff_at_utc' => $row['kickoff_at_utc'],
        'team1' => $row['team1'],
        'team2' => $row['team2'],
        'group_name' => null,
        'ground' => null,
        'score1' => $row['score1'],
        'score2' => $row['score2'],
        'status' => $row['status'],
        'source_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'updated_at' => now_sql(),
    ];

    insert_match_record(db(), $record);

    return [find_match_by_id((int) db()->lastInsertId()), true, false];
}

function find_existing_match_for_gol_row(array $row): ?array
{
    $statement = db()->prepare('SELECT * FROM wc_matches WHERE match_date = ?');
    $statement->execute([$row['date']]);
    $needle1 = normalize_match_name($row['team1']);
    $needle2 = normalize_match_name($row['team2']);

    foreach ($statement->fetchAll() as $match) {
        $team1 = normalize_match_name($match['team1']);
        $team2 = normalize_match_name($match['team2']);

        if ($team1 === $needle1 && $team2 === $needle2) {
            return ['match' => $match, 'reversed' => false];
        }

        if ($team1 === $needle2 && $team2 === $needle1) {
            return ['match' => $match, 'reversed' => true];
        }
    }

    return null;
}

function update_match_result_from_gol(array $match, array $row, bool $reversed): void
{
    if ($row['score1'] === null || $row['score2'] === null || $match['status'] === 'finished') {
        return;
    }

    $score1 = (int) $row['score1'];
    $score2 = (int) $row['score2'];

    if ($reversed) {
        [$score1, $score2] = [$score2, $score1];
    }

    $statement = db()->prepare('
        UPDATE wc_matches
        SET score1 = ?, score2 = ?, status = ?, updated_at = ?
        WHERE id = ?
    ');
    $statement->execute([$score1, $score2, 'finished', now_sql(), (int) $match['id']]);
}

function normalize_match_name(string $name): string
{
    $name = canonical_team_name(clean_gol_text($name));
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $name = preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? $name;

    return $name;
}

function find_match_by_id(int $id): array
{
    $statement = db()->prepare('SELECT * FROM wc_matches WHERE id = ?');
    $statement->execute([$id]);
    $match = $statement->fetch();

    if (!$match) {
        throw new RuntimeException('Imported match could not be loaded.');
    }

    return $match;
}

function find_user_by_id(int $id): ?array
{
    $statement = db()->prepare('SELECT * FROM users WHERE id = ?');
    $statement->execute([$id]);

    return $statement->fetch() ?: null;
}

function upsert_prediction(int $userId, int $matchId, int $pred1, int $pred2, int $points): bool
{
    $existing = db()->prepare('SELECT id FROM predictions WHERE user_id = ? AND match_id = ?');
    $existing->execute([$userId, $matchId]);
    $predictionId = $existing->fetchColumn();

    if ($predictionId) {
        $update = db()->prepare('UPDATE predictions SET pred1 = ?, pred2 = ?, points = ?, updated_at = ? WHERE id = ?');
        $update->execute([$pred1, $pred2, $points, now_sql(), (int) $predictionId]);

        return true;
    }

    $insert = db()->prepare('
        INSERT INTO predictions (user_id, match_id, pred1, pred2, points, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $insert->execute([$userId, $matchId, $pred1, $pred2, $points, now_sql(), now_sql()]);

    return false;
}
