<?php

declare(strict_types=1);

namespace DropTime\Fetch;

/**
 * 速報サイト（results.swimmeets.ca。Hy-Tek MEET MANAGER の速報 HTML 置き場）の
 * ページを読む。通信はせず、受け取った HTML から必要なものを取り出すだけ。
 *
 * サイトの形:
 *   /                    フォルダの一覧（大会ごとに 1 フォルダ、最終更新日時つき）
 *   /<大会>/             frameset。左が evtindex.htm、右が main.htm
 *   /<大会>/evtindex.htm 大会名・日程と、種目ページ（<日付>F001.htm など）へのリンク
 *
 * 大会前は同じ種目ページに「Psych Sheet」（エントリータイムの一覧）が載り、
 * 大会が進むと同じファイル名のまま結果に置き換わる。エントリータイムを
 * 結果として取り込まないよう、結果のページかどうかを必ず isResultsPage() で見分ける。
 */
final class PortalPages
{
    /**
     * ルートの一覧から大会フォルダを取り出す。
     *
     * @return list<array{folder: string, modified: string}> folder は URL エンコードされたままのパス要素
     */
    public static function folders(string $html): array
    {
        preg_match_all(
            '~<a href="/([^"/]+)/">.*?</a></td><td[^>]*>(\d{4}-\d{2}-\d{2} \d{2}:\d{2})</td>~s',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $folders = [];

        foreach ($matches as $match) {
            if (!self::isSafeFolder($match[1])) {
                continue;
            }

            $folders[] = ['folder' => $match[1], 'modified' => $match[2]];
        }

        return $folders;
    }

    /**
     * フォルダ名をローカルのディレクトリ名にも使うので、パスとして安全なものだけ通す。
     * 一覧のリンクは URL エンコード済みなので、%・英数字・. _ - しか現れないはず。
     */
    public static function isSafeFolder(string $folder): bool
    {
        return preg_match('/^[A-Za-z0-9%._\-]+$/', $folder) === 1 && !str_contains($folder, '..');
    }

    /**
     * evtindex.htm から種目ページと、大会の日程を取り出す。
     *
     * @return array{name: string, start: ?string, end: ?string, pages: list<string>, last_event: ?string}
     *         start / end は Y-m-d
     */
    public static function eventIndex(string $html): array
    {
        $name = '';

        if (preg_match('~<h2[^>]*>(?:<[^>]+>)*\s*([^<]+?)\s*<~i', $html, $m) === 1) {
            $name = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $start = null;
        $end = null;

        if (preg_match('~(\d{1,2}/\d{1,2}/\d{4})(?:\s*-\s*(\d{1,2}/\d{1,2}/\d{4}))?~', $html, $m) === 1) {
            $start = self::isoDate($m[1]);
            $end = self::isoDate($m[2] ?? $m[1]);
        }

        preg_match_all('~href="([A-Za-z0-9_\-]+\.htm)"~i', $html, $links);
        $pages = [];
        $lastEvent = null;

        foreach (array_unique($links[1]) as $page) {
            if (stripos($page, 'lastevt') !== false) {
                $lastEvent = $page;
                continue;
            }

            if (preg_match('/(evtindex|index|main)\./i', $page) === 1) {
                continue;
            }

            $pages[] = $page;
        }

        sort($pages, SORT_STRING);

        return ['name' => $name, 'start' => $start, 'end' => $end, 'pages' => $pages, 'last_event' => $lastEvent];
    }

    /**
     * 結果のページか。Psych Sheet・Heat Sheet（大会前の一覧）は結果ではない。
     *
     * 結果の表は見出しに Prelims / Finals の列を持つ（Psych Sheet は Seed の 1 列だけ）。
     */
    public static function isResultsPage(string $html): bool
    {
        if (preg_match('/Psych\s+Sheet|Heat\s+Sheet/i', $html) === 1) {
            return false;
        }

        return preg_match('/^\s*(Name|Team)\b.*\b(Finals|Prelims|Prelim|Final|Swim-off)\b/mi', $html) === 1;
    }

    /**
     * クラブのコード（見本の ZORC など）がチーム欄に現れるか。
     * Hy-Tek はチームを「ZORC」や「ZORC-ON」のように、前後を空白で区切って書く。
     */
    public static function mentionsClub(string $html, string $clubCode): bool
    {
        $code = preg_quote(strtoupper($clubCode), '/');

        return preg_match('/\s' . $code . '(-[A-Z]{2,3})?\s/', $html) === 1;
    }

    private static function isoDate(string $usDate): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!n/j/Y', $usDate);

        return $date === false ? null : $date->format('Y-m-d');
    }
}
