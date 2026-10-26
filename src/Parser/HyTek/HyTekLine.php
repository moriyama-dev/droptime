<?php

declare(strict_types=1);

namespace DropTime\Parser\HyTek;

use DropTime\Domain\Course;
use DropTime\Domain\Stroke;
use DropTime\Domain\SwimTime;

/**
 * Hy-Tek MEET MANAGER の結果 HTML（の <pre> テキスト）1 行の認識。
 *
 * 実データ（results.swimmeets.ca の 2025-2026 の大会）から起こした規則で、
 * 推測は含まない。認識器はマッチした構造か null を返すだけで、状態は
 * 持たない。状態機械は HyTekResultsParser 側にある。
 */
final class HyTekLine
{
    /**
     * 大会ヘッダー行:
     *   "         2026 June Performance Meet - 6/5/2026 to 6/7/2026"
     *
     * @return array{name: string, start: string, end: string}|null
     */
    public static function meetHeader(string $line): ?array
    {
        if (preg_match(
            '/^\s*(?<name>\S.*?)\s+-\s+(?<d1>\d{1,2}\/\d{1,2}\/\d{4})\s+to\s+(?<d2>\d{1,2}\/\d{1,2}\/\d{1,4})\s*$/',
            $line,
            $m
        ) !== 1) {
            return null;
        }

        $start = self::isoDate($m['d1']);

        if ($start === null) {
            return null;
        }

        // 固定幅レイアウトの都合で終了日の年が切れていることがある
        // （"... to 2/22/202"）。年は開始日から補う。
        $end = self::isoDate($m['d2']) ?? self::isoDateWithYear($m['d2'], (int) substr($start, 0, 4));

        return ['name' => trim($m['name']), 'start' => $start, 'end' => $end ?? $start];
    }

    /**
     * ライセンス行（1 ページ目の先頭）:
     *   " Toronto Pan Am Sports Centre - Site License      6/6/2026 - 2:42 PM"
     *   " Richmond Hill Aquatic Club                       07/21/2025 - 2:18 PM"
     *
     * 主催者（またはプール名）として使う。
     */
    public static function licensee(string $line): ?string
    {
        if (preg_match(
            '/^\s*(?<who>\S.*?)\s{2,}\d{1,2}\/\d{1,2}\/\d{4}(\s+-\s+\d{1,2}:\d{2}\s+[AP]M)?\s*$/',
            $line,
            $m
        ) !== 1) {
            return null;
        }

        $who = trim($m['who']);
        $who = preg_replace('/\s*-\s*Site License$/i', '', $who) ?? $who;

        return trim($who);
    }

    /**
     * 種目ヘッダー行:
     *   " Event 47  Girls 10 & Under 50 LC Meter Freestyle"
     *   " Event 21  Mixed 10 & Under 200 SC Meter Freestyle Relay"
     *   "(Event 7  Girls 10 & Under 200 SC Meter Freestyle continued)" ← 続きページ
     *
     * @return array{number: string, gender: string, age_group: ?string,
     *               distance: int, course: Course, stroke: Stroke,
     *               is_relay: bool, continued: bool}|null
     */
    public static function eventHeader(string $line): ?array
    {
        $continued = false;
        $work = trim($line);

        if (preg_match('/^\(\s*(?<inner>Event\b.*?)\s+continued\s*\)$/i', $work, $m) === 1) {
            $continued = true;
            $work = $m['inner'];
        }

        if (preg_match(
            '/^Event\s+(?<num>\d+[A-Z]?)\s+(?<gender>Girls|Boys|Women|Men|Mixed)\s+'
            . '(?<age>.*?)\s*(?<dist>\d+)\s+(?<course>SC Meter|LC Meter|Meter|Yard)\s+'
            . '(?<stroke>Freestyle Relay|Medley Relay|Freestyle|Backstroke|Breaststroke|Butterfly|IM|Individual Medley)'
            . '(?<variant>\s+(?:Time Trial|Swim-off))?\s*$/i',
            $work,
            $m
        ) !== 1) {
            return null;
        }

        $isRelay = stripos($m['stroke'], 'Relay') !== false;

        // タイムトライアルは本種目と番号が重なるため、番号に印を付けて分ける。
        $number = strtoupper($m['num']);

        if (isset($m['variant']) && stripos($m['variant'], 'Time Trial') !== false) {
            $number .= 'TT';
        }

        return [
            'number' => $number,
            'gender' => match (strtolower($m['gender'])) {
                'girls', 'women' => 'F',
                'boys', 'men' => 'M',
                default => 'X',
            },
            'age_group' => trim($m['age']) === '' ? null : trim($m['age']),
            'distance' => (int) $m['dist'],
            'course' => match (strtolower($m['course'])) {
                'lc meter' => Course::LCM,
                'yard' => Course::SCY,
                default => Course::SCM,
            },
            'stroke' => match (strtolower($m['stroke'])) {
                'backstroke' => Stroke::BACK,
                'breaststroke' => Stroke::BREAST,
                'butterfly' => Stroke::FLY,
                'im', 'individual medley' => Stroke::IM,
                'medley relay' => Stroke::MEDLEY,
                default => Stroke::FREE,
            },
            'is_relay' => $isRelay,
            'continued' => $continued,
        ];
    }

    /**
     * 列見出し行。どの列があるかで、その後の行の解釈が決まる。
     *   "    Name                  Age Team                 Seed     Finals  Points"
     *
     * @return array{has_seed: bool, has_prelims: bool, has_finals: bool, has_points: bool}|null
     */
    public static function columnHeader(string $line): ?array
    {
        if (preg_match('/^\s+(Name|Team)\b.*\b(Finals|Prelims)\b/', $line) !== 1) {
            return null;
        }

        return [
            'has_seed' => (bool) preg_match('/\bSeed\b/', $line),
            'has_prelims' => (bool) preg_match('/\bPrelims\b/', $line),
            'has_finals' => (bool) preg_match('/\bFinals\b/', $line),
            'has_points' => (bool) preg_match('/\bPoints\b/', $line),
        ];
    }

    /**
     * セクション見出し:  "                === A - Final ===" / "=== Preliminaries ==="
     *
     * @return string|null 'final' | 'prelim' | 'swimoff'
     */
    public static function sectionMarker(string $line): ?string
    {
        if (preg_match('/^\s*=+\s*(?<label>[^=]+?)\s*=+\s*$/', $line, $m) !== 1) {
            return null;
        }

        $label = strtolower($m['label']);

        if (str_contains($label, 'swim')) {
            return 'swimoff';
        }

        if (str_contains($label, 'prelim')) {
            return 'prelim';
        }

        if (str_contains($label, 'final')) {
            return 'final';
        }

        // "=== - Time Trial ===" など。round は変えず、区切りとしてだけ扱う。
        return 'other';
    }

    /**
     * 個人の結果行:
     *   "  1 Dese, Zomudaz          10 NYAC                36.20      34.82   20"
     *   " -- Mulile, Sukol J        16 NYAC                   NT   X2:27.48"
     *
     * @return array{place: ?int, last: string, first: string, age: ?int,
     *               team: string, tail: list<string>}|null
     */
    public static function individualResult(string $line): ?array
    {
        // last に _ を許さないのは、パラ分類コードが名前に融着した破損行
        // （"Sharma _s8, Amogh"）を誤って選手として登録しないため。
        // チーム欄は固定幅で切れた正式名（"Pointe-Claire Sw"）があるため
        // 2 語まで許す。コンマ入り（破損行）は弾く。
        if (preg_match(
            '/^\s{0,4}(?<place>\d{1,3}|-{1,3})\s+(?<last>[^,_]+?),\s*(?<first>.*?)\s+'
            . '(?<age>\d{1,3})\s+(?<team>[^\s,]+(?:\s[^\s,]+)?)\s{2,}'
            . '(?<tail>(?:NT|DQ|NS|SCR|DFS|[XxJj]{0,2}\d[\d:.]*)(?:\s+\S+)*)\s*$/',
            $line,
            $m
        ) !== 1) {
            return null;
        }

        return [
            'place' => ctype_digit($m['place']) ? (int) $m['place'] : null,
            'last' => trim($m['last']),
            'first' => trim($m['first']),
            'age' => (int) $m['age'],
            'team' => $m['team'],
            'tail' => preg_split('/\s+/', trim($m['tail'])) ?: [],
        ];
    }

    /**
     * リレーの結果行:
     *   "  1 RHAC  'A'                           2:15.89    2:16.44   48"
     *
     * @return array{place: ?int, team: string, letter: string, tail: list<string>}|null
     */
    public static function relayResult(string $line): ?array
    {
        if (preg_match(
            '/^\s{0,4}(?<place>\d{1,3}|-{1,3})\s+(?<team>[A-Za-z][A-Za-z0-9&\'.\/\-]*)\s+'
            . "'(?<letter>[A-Z])'\s{2,}(?<tail>\S.*?)\s*$/",
            $line,
            $m
        ) !== 1) {
            return null;
        }

        return [
            'place' => ctype_digit($m['place']) ? (int) $m['place'] : null,
            'team' => $m['team'],
            'letter' => $m['letter'],
            'tail' => preg_split('/\s+/', trim($m['tail'])) ?: [],
        ];
    }

    /** リレーの泳者行:  "     1) Vumo, Lidi R M10              2) Vi, Folir M10" */
    public static function isRelayLegs(string $line): bool
    {
        return preg_match('/^\s+\d\)\s+\S/', $line) === 1;
    }

    /**
     * スプリット行。累積タイム（と括弧内の区間タイム）だけが並ぶ:
     *   "                 35.26      1:10.38 (35.12)"
     *
     * @return list<int>|null 累積タイム（センチ秒）の列
     */
    public static function splits(string $line): ?array
    {
        $trimmed = trim($line);

        // DQ になった泳ぎでも途中までのスプリットが印字される。
        if ($trimmed === '' || preg_match('/^[\d:.()\s]*(DQ|NS)?[\d:.()\s]*$/', $trimmed) !== 1) {
            return null;
        }

        $cumulative = [];
        $sawTime = false;
        $sawStatus = str_contains($trimmed, 'DQ') || str_contains($trimmed, 'NS');

        // 括弧の外にあるタイムだけが累積。括弧内は直前区間なので捨てる。
        $outside = preg_replace('/\([^)]*\)/', ' ', $trimmed) ?? $trimmed;

        foreach (preg_split('/\s+/', trim($outside)) ?: [] as $token) {
            if ($token === '' || in_array(strtoupper($token), ['DQ', 'NS'], true)) {
                continue;
            }

            $cs = SwimTime::parse($token);

            if ($cs === null) {
                return null;
            }

            $sawTime = true;
            $cumulative[] = $cs;
        }

        // DQ だけの行（途中棄権のスプリット）は「空のスプリット行」として認識する。
        return $sawTime ? $cumulative : ($sawStatus ? [] : null);
    }

    /**
     * 結果側のタイム欄トークンの解釈。
     *
     * @return array{status: string, time_cs: ?int}|null 解釈できなければ null
     */
    public static function resultToken(string $token): ?array
    {
        $upper = strtoupper($token);

        if (in_array($upper, ['DQ', 'NS', 'SCR', 'DFS'], true)) {
            return ['status' => $upper, 'time_cs' => null];
        }

        // X = 賞典外（エキシビション）、J = 判定タイム。タイムとしては有効。
        $bare = preg_replace('/^[XJ]+/i', '', $token) ?? $token;
        $cs = SwimTime::parse($bare);

        if ($cs === null) {
            return null;
        }

        return ['status' => 'OK', 'time_cs' => $cs];
    }

    /**
     * シード欄トークン。NT はタイム無し。
     *
     * @return array{time_cs: ?int, is_nt: bool}|null
     */
    public static function seedToken(string $token): ?array
    {
        if (strtoupper($token) === 'NT') {
            return ['time_cs' => null, 'is_nt' => true];
        }

        $bare = preg_replace('/^[XJ]+/i', '', $token) ?? $token;
        $cs = SwimTime::parse($bare);

        if ($cs === null) {
            return null;
        }

        return ['time_cs' => $cs, 'is_nt' => false];
    }

    /** 罫線・空行・ページ装飾など、無視してよい行。 */
    public static function isNoise(string $line): bool
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return true;
        }

        // "=========="（セクション見出しは sectionMarker が先に拾う）
        if (preg_match('/^[=_\-]+$/', $trimmed) === 1) {
            return true;
        }

        return in_array($trimmed, ['Results', 'Latest Completed Event', 'Last Completed Event'], true)
            || str_starts_with($trimmed, 'Note:')
            || str_starts_with($trimmed, 'Meet Qualifying')
            || str_starts_with($trimmed, 'Qualifying:')
            || str_starts_with($trimmed, 'Licensed to')
            || preg_match('/^HY-TEK/i', $trimmed) === 1
            || preg_match('/^(Session|Friday|Saturday|Sunday|Monday|Tuesday|Wednesday|Thursday)\b/', $trimmed) === 1;
    }

    /** チーム得点などのスキップすべきブロックの見出し。次の Event まで読み飛ばす。 */
    public static function isScoresHeader(string $line): bool
    {
        return preg_match('/Team Scores|Team Rankings|Scores\s+-\s+/i', $line) === 1;
    }

    /** 年が欠けた "2/22/202" のような日付を、指定した年で復元する。 */
    private static function isoDateWithYear(string $raw, int $year): ?string
    {
        $parts = explode('/', $raw);

        if (count($parts) < 2) {
            return null;
        }

        $month = (int) $parts[0];
        $day = (int) $parts[1];

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** "6/5/2026" → "2026-06-05" */
    private static function isoDate(string $raw): ?string
    {
        $parts = explode('/', $raw);

        if (count($parts) !== 3) {
            return null;
        }

        [$month, $day, $year] = array_map(intval(...), $parts);

        if ($year < 1000 || !checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
