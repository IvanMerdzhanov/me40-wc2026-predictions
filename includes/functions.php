<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $message, string $type = 'info'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

function consume_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return $messages;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $posted = $_POST['csrf'] ?? '';
    if (!is_string($posted) || !hash_equals(csrf_token(), $posted)) {
        http_response_code(419);
        exit('Invalid form token.');
    }
}

function current_user(): ?array
{
    static $user = false;

    if ($user !== false) {
        return $user;
    }

    if (empty($_SESSION['user_id'])) {
        $user = null;
        return null;
    }

    $statement = db()->prepare('SELECT * FROM users WHERE id = ?');
    $statement->execute([(int) $_SESSION['user_id']]);
    $user = $statement->fetch() ?: null;

    return $user;
}

function refresh_current_user(): void
{
    unset($_SESSION['user_id']);
}

function is_admin(?array $user = null): bool
{
    $user ??= current_user();

    return $user !== null && $user['role'] === 'admin' && strcasecmp($user['username'], 'CSKA') === 0;
}

function user_is_verified(?array $user = null): bool
{
    $user ??= current_user();

    return $user !== null && ((int) $user['is_verified'] === 1 || is_admin($user));
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }

    return $user;
}

function require_verified_user(): array
{
    $user = require_login();
    if (!user_is_verified($user)) {
        redirect('pending.php');
    }

    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (!is_admin($user)) {
        http_response_code(403);
        exit('Admin access only.');
    }

    return $user;
}

function admin_exists(): bool
{
    $statement = db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND username = 'CSKA'");

    return (int) $statement->fetchColumn() > 0;
}

function format_match_score(array $match): string
{
    if ($match['status'] !== 'finished' || $match['score1'] === null || $match['score2'] === null) {
        return '-';
    }

    return (int) $match['score1'] . ':' . (int) $match['score2'];
}

function common_match_results(?int $limit = null): array
{
    $finishedScorelines = db()->query("
        SELECT score1, score2
        FROM wc_matches
        WHERE status = 'finished'
          AND score1 IS NOT NULL
          AND score2 IS NOT NULL
          AND external_id LIKE 'wc2026-%'
    ")->fetchAll();
    $scorelineCounts = [];

    foreach ($finishedScorelines as $scoreline) {
        $goals = [(int) $scoreline['score1'], (int) $scoreline['score2']];
        rsort($goals, SORT_NUMERIC);
        $key = $goals[0] . ':' . $goals[1];
        $scorelineCounts[$key] = ($scorelineCounts[$key] ?? 0) + 1;
    }

    uksort($scorelineCounts, static function (string $left, string $right) use ($scorelineCounts): int {
        $countCompare = $scorelineCounts[$right] <=> $scorelineCounts[$left];

        if ($countCompare !== 0) {
            return $countCompare;
        }

        [$leftHigh, $leftLow] = array_map('intval', explode(':', $left));
        [$rightHigh, $rightLow] = array_map('intval', explode(':', $right));

        return ($rightHigh + $rightLow) <=> ($leftHigh + $leftLow)
            ?: $rightHigh <=> $leftHigh
            ?: $rightLow <=> $leftLow;
    });

    if ($limit === null) {
        return $scorelineCounts;
    }

    return array_slice($scorelineCounts, 0, $limit, true);
}

function canonical_team_name(string $name): string
{
    $clean = trim(preg_replace('/\s+/u', ' ', html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? $name);
    $key = team_lookup_key($clean);
    $aliases = team_name_aliases();

    return $aliases[$key] ?? $clean;
}

function canonical_team_key(string $name): string
{
    return team_lookup_key(canonical_team_name($name));
}

function match_fixture_key(array $match): string
{
    $teams = [
        canonical_team_key((string) ($match['team1'] ?? '')),
        canonical_team_key((string) ($match['team2'] ?? '')),
    ];
    sort($teams, SORT_STRING);

    return (string) ($match['match_date'] ?? '') . '|' . implode('|', $teams);
}

function match_orientation(array $sourceMatch, array $targetMatch): string
{
    $source1 = canonical_team_key((string) ($sourceMatch['team1'] ?? ''));
    $source2 = canonical_team_key((string) ($sourceMatch['team2'] ?? ''));
    $target1 = canonical_team_key((string) ($targetMatch['team1'] ?? ''));
    $target2 = canonical_team_key((string) ($targetMatch['team2'] ?? ''));

    if ($source1 === $target1 && $source2 === $target2) {
        return 'same';
    }

    if ($source1 === $target2 && $source2 === $target1) {
        return 'reversed';
    }

    return 'unknown';
}

function match_source_priority(array $match): int
{
    $externalId = (string) ($match['external_id'] ?? '');

    if (str_starts_with($externalId, 'wc2026-')) {
        return 0;
    }

    if (str_starts_with($externalId, 'gol-')) {
        return 2;
    }

    return 1;
}

function team_lookup_key(string $name): string
{
    $name = str_replace("\xc2\xa0", ' ', $name);
    $name = preg_replace('/\bteam image\b/iu', '', $name) ?? $name;
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $name = preg_replace('/[^\p{L}\p{N}]+/u', '', $name) ?? $name;

    return trim($name);
}

function team_name_aliases(): array
{
    static $aliases = [
        'сащ' => 'United States',
        'съединенитещати' => 'United States',
        'съединениамериканскищати' => 'United States',
        'usa' => 'United States',
        'usmnt' => 'United States',
        'unitedstates' => 'United States',
        'unitedstatesofamerica' => 'United States',
        'канада' => 'Canada',
        'canada' => 'Canada',
        'мексико' => 'Mexico',
        'mexico' => 'Mexico',
        'аржентина' => 'Argentina',
        'argentina' => 'Argentina',
        'бразилия' => 'Brazil',
        'brazil' => 'Brazil',
        'уругвай' => 'Uruguay',
        'uruguay' => 'Uruguay',
        'колумбия' => 'Colombia',
        'colombia' => 'Colombia',
        'еквадор' => 'Ecuador',
        'ecuador' => 'Ecuador',
        'парагвай' => 'Paraguay',
        'paraguay' => 'Paraguay',
        'чили' => 'Chile',
        'chile' => 'Chile',
        'перу' => 'Peru',
        'peru' => 'Peru',
        'венецуела' => 'Venezuela',
        'venezuela' => 'Venezuela',
        'боливия' => 'Bolivia',
        'bolivia' => 'Bolivia',
        'босна' => 'Bosnia & Herzegovina',
        'боснаихецеговина' => 'Bosnia & Herzegovina',
        'босна&хецеговина' => 'Bosnia & Herzegovina',
        'bosniaandherzegovina' => 'Bosnia & Herzegovina',
        'bosniaherzegovina' => 'Bosnia & Herzegovina',
        'англия' => 'England',
        'england' => 'England',
        'шотландия' => 'Scotland',
        'scotland' => 'Scotland',
        'уелс' => 'Wales',
        'wales' => 'Wales',
        'франция' => 'France',
        'france' => 'France',
        'германия' => 'Germany',
        'germany' => 'Germany',
        'испания' => 'Spain',
        'spain' => 'Spain',
        'португалия' => 'Portugal',
        'portugal' => 'Portugal',
        'италия' => 'Italy',
        'italy' => 'Italy',
        'нидерландия' => 'Netherlands',
        'холандия' => 'Netherlands',
        'netherlands' => 'Netherlands',
        'белгия' => 'Belgium',
        'belgium' => 'Belgium',
        'швейцария' => 'Switzerland',
        'switzerland' => 'Switzerland',
        'австрия' => 'Austria',
        'austria' => 'Austria',
        'хърватия' => 'Croatia',
        'croatia' => 'Croatia',
        'дания' => 'Denmark',
        'denmark' => 'Denmark',
        'норвегия' => 'Norway',
        'norway' => 'Norway',
        'швеция' => 'Sweden',
        'sweden' => 'Sweden',
        'полша' => 'Poland',
        'poland' => 'Poland',
        'сърбия' => 'Serbia',
        'serbia' => 'Serbia',
        'украйна' => 'Ukraine',
        'ukraine' => 'Ukraine',
        'чехия' => 'Czech Republic',
        'чешкарепублика' => 'Czech Republic',
        'czechrepublic' => 'Czech Republic',
        'czechia' => 'Czech Republic',
        'czechrep' => 'Czech Republic',
        'турция' => 'Turkey',
        'turkey' => 'Turkey',
        'turkiye' => 'Turkey',
        'унгария' => 'Hungary',
        'hungary' => 'Hungary',
        'румъния' => 'Romania',
        'romania' => 'Romania',
        'гърция' => 'Greece',
        'greece' => 'Greece',
        'българия' => 'Bulgaria',
        'bulgaria' => 'Bulgaria',
        'словения' => 'Slovenia',
        'slovenia' => 'Slovenia',
        'словакия' => 'Slovakia',
        'slovakia' => 'Slovakia',
        'албания' => 'Albania',
        'albania' => 'Albania',
        'австралия' => 'Australia',
        'australia' => 'Australia',
        'япония' => 'Japan',
        'japan' => 'Japan',
        'южнакорея' => 'South Korea',
        'корея' => 'South Korea',
        'републикакорея' => 'South Korea',
        'southkorea' => 'South Korea',
        'korearepublic' => 'South Korea',
        'иран' => 'Iran',
        'iran' => 'Iran',
        'iriran' => 'Iran',
        'катар' => 'Qatar',
        'qatar' => 'Qatar',
        'саудитскаарабия' => 'Saudi Arabia',
        'saudiarabia' => 'Saudi Arabia',
        'ирак' => 'Iraq',
        'iraq' => 'Iraq',
        'узбекистан' => 'Uzbekistan',
        'uzbekistan' => 'Uzbekistan',
        'йордания' => 'Jordan',
        'jordan' => 'Jordan',
        'оае' => 'United Arab Emirates',
        'обединениарабскиемирства' => 'United Arab Emirates',
        'uae' => 'United Arab Emirates',
        'unitedarabemirates' => 'United Arab Emirates',
        'oman' => 'Oman',
        'оман' => 'Oman',
        'бахрейн' => 'Bahrain',
        'bahrain' => 'Bahrain',
        'кувейт' => 'Kuwait',
        'kuwait' => 'Kuwait',
        'индонезия' => 'Indonesia',
        'indonesia' => 'Indonesia',
        'китай' => 'China',
        'china' => 'China',
        'севернакорея' => 'North Korea',
        'northkorea' => 'North Korea',
        'новазеландия' => 'New Zealand',
        'newzealand' => 'New Zealand',
        'мароко' => 'Morocco',
        'morocco' => 'Morocco',
        'сенегал' => 'Senegal',
        'senegal' => 'Senegal',
        'тунис' => 'Tunisia',
        'tunisia' => 'Tunisia',
        'египет' => 'Egypt',
        'egypt' => 'Egypt',
        'алжир' => 'Algeria',
        'algeria' => 'Algeria',
        'нигерия' => 'Nigeria',
        'nigeria' => 'Nigeria',
        'гана' => 'Ghana',
        'ghana' => 'Ghana',
        'камерун' => 'Cameroon',
        'cameroon' => 'Cameroon',
        'котдивоар' => 'Cote d\'Ivoire',
        'брягнаслоноватакост' => 'Cote d\'Ivoire',
        'côtedivoire' => 'Cote d\'Ivoire',
        'cotedivoire' => 'Cote d\'Ivoire',
        'ivorycoast' => 'Cote d\'Ivoire',
        'южнаафрика' => 'South Africa',
        'southafrica' => 'South Africa',
        'кабоверде' => 'Cape Verde',
        'capeverde' => 'Cape Verde',
        'мали' => 'Mali',
        'mali' => 'Mali',
        'дрконго' => 'DR Congo',
        'демократичнарепубликаконго' => 'DR Congo',
        'drcongo' => 'DR Congo',
        'congodr' => 'DR Congo',
        'democraticrepublicofcongo' => 'DR Congo',
        'габон' => 'Gabon',
        'gabon' => 'Gabon',
        'буркинафасо' => 'Burkina Faso',
        'burkinafaso' => 'Burkina Faso',
        'гвинея' => 'Guinea',
        'guinea' => 'Guinea',
        'уганда' => 'Uganda',
        'uganda' => 'Uganda',
        'замбия' => 'Zambia',
        'zambia' => 'Zambia',
        'бенин' => 'Benin',
        'benin' => 'Benin',
        'либия' => 'Libya',
        'libya' => 'Libya',
        'екваториалнагвинея' => 'Equatorial Guinea',
        'equatorialguinea' => 'Equatorial Guinea',
        'хондурос' => 'Honduras',
        'honduras' => 'Honduras',
        'костарика' => 'Costa Rica',
        'costarica' => 'Costa Rica',
        'панама' => 'Panama',
        'panama' => 'Panama',
        'ямайка' => 'Jamaica',
        'jamaica' => 'Jamaica',
        'хаити' => 'Haiti',
        'haiti' => 'Haiti',
        'елсалвадор' => 'El Salvador',
        'elsalvador' => 'El Salvador',
        'тринидадитобаго' => 'Trinidad and Tobago',
        'trinidadandtobago' => 'Trinidad and Tobago',
        'кюрасао' => 'Curacao',
        'курасао' => 'Curacao',
        'curaçao' => 'Curacao',
        'curacao' => 'Curacao',
    ];

    return $aliases;
}

function outcome_for(int $home, int $away): string
{
    if ($home > $away) {
        return '1';
    }

    if ($home < $away) {
        return '2';
    }

    return 'X';
}

function prediction_points(int $pred1, int $pred2, ?int $score1, ?int $score2): int
{
    if ($score1 === null || $score2 === null) {
        return 0;
    }

    if ($pred1 === $score1 && $pred2 === $score2) {
        return 7;
    }

    return outcome_for($pred1, $pred2) === outcome_for($score1, $score2) ? 3 : 0;
}

function match_is_locked(array $match): bool
{
    if ($match['status'] === 'finished') {
        return true;
    }

    if (empty($match['kickoff_at_utc'])) {
        return false;
    }

    $kickoff = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $match['kickoff_at_utc'], new DateTimeZone('UTC'));
    if (!$kickoff) {
        return false;
    }

    return new DateTimeImmutable('now', new DateTimeZone('UTC')) >= $kickoff;
}

function now_sql(): string
{
    return gmdate('Y-m-d H:i:s');
}
