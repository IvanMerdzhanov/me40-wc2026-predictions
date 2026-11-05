<?php
declare(strict_types=1);

const WORLDCUP_JSON_URL = 'https://raw.githubusercontent.com/openfootball/worldcup.json/master/2026/worldcup.json';

function fetch_worldcup_json(string $url = WORLDCUP_JSON_URL): array
{
    $body = null;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'wc2026-predictions/1.0',
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $status >= 400) {
            throw new RuntimeException('Could not fetch World Cup JSON: ' . ($error ?: 'HTTP ' . $status));
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'header' => "User-Agent: wc2026-predictions/1.0\r\n",
            ],
        ]);
        $body = file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException('Could not fetch World Cup JSON.');
        }
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['matches']) || !is_array($data['matches'])) {
        throw new RuntimeException('World Cup JSON is not in the expected format.');
    }

    return $data;
}

function import_worldcup_matches(string $url = WORLDCUP_JSON_URL): array
{
    return apply_worldcup_import_preview(build_worldcup_import_preview('all', $url));
}

function build_worldcup_import_preview(string $mode = 'all', string $url = WORLDCUP_JSON_URL): array
{
    $data = fetch_worldcup_json($url);
    $pdo = db();
    $items = [];

    foreach ($data['matches'] as $index => $match) {
        if (!is_array($match)) {
            continue;
        }

        $record = worldcup_match_record($match, $index);

        if ($record['match_date'] === '') {
            continue;
        }

        $existing = $pdo->prepare('SELECT id FROM wc_matches WHERE external_id = ?');
        $existing->execute([$record['external_id']]);
        $matchId = $existing->fetchColumn();

        $existingMatch = null;
        if ($matchId) {
            $load = $pdo->prepare('SELECT * FROM wc_matches WHERE id = ?');
            $load->execute([(int) $matchId]);
            $existingMatch = $load->fetch() ?: null;
        }

        $change = classify_worldcup_import_change($record, $existingMatch);
        if ($change === null) {
            continue;
        }

        if ($mode === 'results' && $change !== 'result') {
            continue;
        }

        $items[] = [
            'change' => $change,
            'existing_id' => $existingMatch ? (int) $existingMatch['id'] : null,
            'record' => $record,
            'old_score' => $existingMatch ? score_pair($existingMatch['score1'], $existingMatch['score2']) : null,
        ];
    }

    return [
        'mode' => $mode,
        'created_at' => now_sql(),
        'items' => $items,
    ];
}

function apply_worldcup_import_preview(array $preview): array
{
    $pdo = db();
    $imported = 0;
    $updated = 0;
    $finished = 0;
    $resultUpdates = 0;

    foreach (($preview['items'] ?? []) as $item) {
        if (!is_array($item) || !isset($item['record']) || !is_array($item['record'])) {
            continue;
        }

        $record = $item['record'];
        $existingId = isset($item['existing_id']) ? (int) $item['existing_id'] : 0;

        if ($existingId > 0) {
            update_match_record($pdo, $record, $existingId);
            $updated++;
        } else {
            insert_match_record($pdo, $record);
            $imported++;
        }

        if (($record['status'] ?? '') === 'finished') {
            $finished++;
        }

        if (($item['change'] ?? '') === 'result') {
            $resultUpdates++;
        }
    }

    $recalculated = recalculate_all_points();

    return [
        'imported' => $imported,
        'updated' => $updated,
        'finished' => $finished,
        'result_updates' => $resultUpdates,
        'recalculated' => $recalculated,
    ];
}

function worldcup_match_record(array $match, int $index): array
{
    $ft = $match['score']['ft'] ?? null;
    $score1 = is_array($ft) && array_key_exists(0, $ft) ? (int) $ft[0] : null;
    $score2 = is_array($ft) && array_key_exists(1, $ft) ? (int) $ft[1] : null;
    $status = $score1 !== null && $score2 !== null ? 'finished' : 'scheduled';

    return [
        'external_id' => 'wc2026-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
        'match_no' => $index + 1,
        'round_name' => (string) ($match['round'] ?? 'Matchday'),
        'match_date' => (string) ($match['date'] ?? ''),
        'match_time' => isset($match['time']) ? (string) $match['time'] : null,
        'kickoff_at_utc' => parse_kickoff_utc((string) ($match['date'] ?? ''), isset($match['time']) ? (string) $match['time'] : null),
        'team1' => (string) ($match['team1'] ?? 'TBD'),
        'team2' => (string) ($match['team2'] ?? 'TBD'),
        'group_name' => isset($match['group']) ? (string) $match['group'] : null,
        'ground' => isset($match['ground']) ? (string) $match['ground'] : null,
        'score1' => $score1,
        'score2' => $score2,
        'status' => $status,
        'source_payload' => json_encode($match, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'updated_at' => now_sql(),
    ];
}

function classify_worldcup_import_change(array $record, ?array $existing): ?string
{
    if ($existing === null) {
        return 'new';
    }

    $newScore = score_pair($record['score1'], $record['score2']);
    $oldScore = score_pair($existing['score1'], $existing['score2']);

    if ($newScore !== null && $newScore !== $oldScore) {
        return 'result';
    }

    foreach (['team1', 'team2', 'match_date', 'match_time', 'round_name', 'group_name', 'ground', 'kickoff_at_utc'] as $field) {
        if (($record[$field] ?? null) !== ($existing[$field] ?? null)) {
            return 'fixture';
        }
    }

    return null;
}

function score_pair(mixed $score1, mixed $score2): ?string
{
    if ($score1 === null || $score2 === null) {
        return null;
    }

    return (int) $score1 . ':' . (int) $score2;
}

function insert_match_record(PDO $pdo, array $record): void
{
    $statement = $pdo->prepare('
        INSERT INTO wc_matches (
            external_id, match_no, round_name, match_date, match_time, kickoff_at_utc,
            team1, team2, group_name, ground, score1, score2, status, source_payload, updated_at
        ) VALUES (
            :external_id, :match_no, :round_name, :match_date, :match_time, :kickoff_at_utc,
            :team1, :team2, :group_name, :ground, :score1, :score2, :status, :source_payload, :updated_at
        )
    ');
    $statement->execute($record);
}

function update_match_record(PDO $pdo, array $record, int $id): void
{
    $record['id'] = $id;
    $statement = $pdo->prepare('
        UPDATE wc_matches
        SET match_no = :match_no,
            round_name = :round_name,
            match_date = :match_date,
            match_time = :match_time,
            kickoff_at_utc = :kickoff_at_utc,
            team1 = :team1,
            team2 = :team2,
            group_name = :group_name,
            ground = :ground,
            score1 = :score1,
            score2 = :score2,
            status = :status,
            source_payload = :source_payload,
            updated_at = :updated_at
        WHERE id = :id
    ');
    unset($record['external_id']);
    $statement->execute($record);
}

function parse_kickoff_utc(string $date, ?string $time): ?string
{
    if ($date === '' || $time === null || $time === '') {
        return null;
    }

    if (!preg_match('/^(\d{1,2}):(\d{2})(?:\s+UTC([+-]\d{1,2}))?$/', trim($time), $matches)) {
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));
    $hour = (int) $matches[1];
    $minute = (int) $matches[2];
    $offset = isset($matches[3]) ? (int) $matches[3] : 0;
    $timestamp = gmmktime($hour - $offset, $minute, 0, $month, $day, $year);

    return gmdate('Y-m-d H:i:s', $timestamp);
}

function recalculate_all_points(): int
{
    $pdo = db();
    $statement = $pdo->query('
        SELECT p.id, p.pred1, p.pred2, m.score1, m.score2
        FROM predictions p
        INNER JOIN wc_matches m ON m.id = p.match_id
    ');
    $update = $pdo->prepare('UPDATE predictions SET points = ?, updated_at = ? WHERE id = ?');
    $count = 0;

    foreach ($statement->fetchAll() as $row) {
        $points = prediction_points(
            (int) $row['pred1'],
            (int) $row['pred2'],
            $row['score1'] === null ? null : (int) $row['score1'],
            $row['score2'] === null ? null : (int) $row['score2']
        );
        $update->execute([$points, now_sql(), (int) $row['id']]);
        $count++;
    }

    return $count;
}
