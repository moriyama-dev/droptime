<?php

declare(strict_types=1);

namespace DropTime\Fetch;

/**
 * 速報サイトの目次（evtindex.htm）から、大会のプログラム（セッションと種目の並び）を読む。
 *
 *   <h3>Session 2A - 1:45 PM<br>
 *   Friday 11/13/2026</h3>
 *   <a href="261113F013.htm" target=main>#13 Girls 11-12 200 Breast </a><br>
 *   <a href="261113P001.htm" target=main>#1 Girls 13&O 100 Free Prelims </a><br>
 *
 * 日付は "11/13/2026" と "2025-06-06" の両方がある。種目の round は表示名の末尾
 * （Prelims → prelim、Finals → final、無印 → timed）で決める。スイムオフは除く。
 * 同じ種目が 2 つのセッションに出る大会（長い種目を分けて泳ぐ）は、最初のセッションに置く。
 */
final class MeetProgram
{
    /**
     * @return list<array{code: string, name: string, date: ?string, start_time: ?string,
     *                    events: list<array{number: string, round: string, page: string}>}>
     */
    public static function parse(string $html): array
    {
        $parts = preg_split('~<h3[^>]*>~i', $html) ?: [];
        array_shift($parts); // 最初のセッションより前（大会名・リンク類）
        $sessions = [];
        $seen = [];

        foreach ($parts as $part) {
            [$heading, $body] = array_pad(preg_split('~</h3>~i', $part, 2) ?: [], 2, '');
            $heading = trim(html_entity_decode(strip_tags(str_ireplace(['<br>', '<br />', '<br/>'], "\n", $heading)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (preg_match('/^Session\s+(?<code>\S+)(?:\s*-\s*(?<time>\d{1,2}:\d{2}\s*[AP]M))?/i', $heading, $m) !== 1) {
                continue;
            }

            $session = [
                'code' => $m['code'],
                'name' => trim(strtok($heading, "\n") ?: $heading),
                'date' => self::date($heading),
                'start_time' => isset($m['time']) && $m['time'] !== '' ? self::time($m['time']) : null,
                'events' => [],
            ];

            preg_match_all('~<a\s+href="([A-Za-z0-9_\-]+\.htm)"[^>]*>\s*#([^<]*)</a>~i', $body, $links, PREG_SET_ORDER);

            foreach ($links as $link) {
                $label = trim(html_entity_decode($link[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $event = self::event($label);

                if ($event === null) {
                    continue;
                }

                $key = $event['number'] . '|' . $event['round'];

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $session['events'][] = $event + ['page' => $link[1]];
            }

            $sessions[] = $session;
        }

        return $sessions;
    }

    /**
     * "13 Girls 11-12 200 Breast" / "1 Girls 13&O 100 Free Prelims" / "1S ... Swim-off"
     *
     * @return array{number: string, round: string}|null
     */
    private static function event(string $label): ?array
    {
        if (preg_match('/^(?<num>\d+[A-Z]?)\s+(?<rest>.*)$/', $label, $m) !== 1) {
            return null;
        }

        $rest = $m['rest'];

        if (preg_match('/Swim-?off/i', $rest) === 1 || preg_match('/^\d+S$/', $m['num']) === 1) {
            return null;
        }

        $number = $m['num'];

        // タイムトライアルは本種目と番号が重なるので、パーサーと同じ印を付ける。
        if (preg_match('/Time Trial/i', $rest) === 1) {
            $number .= 'TT';
        }

        $round = match (true) {
            preg_match('/\bPrelims?\s*$/i', $rest) === 1 => 'prelim',
            preg_match('/\bFinals?\s*$/i', $rest) === 1 => 'final',
            default => 'timed',
        };

        return ['number' => $number, 'round' => $round];
    }

    private static function date(string $heading): ?string
    {
        if (preg_match('~\b(\d{4})-(\d{2})-(\d{2})\b~', $heading, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }

        if (preg_match('~\b(\d{1,2})/(\d{1,2})/(\d{4})\b~', $heading, $m) === 1) {
            return checkdate((int) $m[1], (int) $m[2], (int) $m[3])
                ? sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2])
                : null;
        }

        return null;
    }

    /** "1:45 PM" → "13:45" */
    public static function time(string $text): ?string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})\s*([AP])M$/i', trim($text), $m) !== 1) {
            return null;
        }

        $hour = (int) $m[1] % 12 + (strtoupper($m[3]) === 'P' ? 12 : 0);

        return sprintf('%02d:%02d', $hour, (int) $m[2]);
    }
}
