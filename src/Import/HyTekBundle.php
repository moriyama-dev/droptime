<?php

declare(strict_types=1);

namespace DropTime\Import;

/**
 * 速報サイトから保存した Hy-Tek のイベントページ一式を、1 つの HTML にまとめる。
 *
 * 各ページの <pre> テキストだけを取り出して並べ直す。こうしておくと、
 * Web のアップロードと同じ経路（保存 → テキスト化 → パース）にそのまま流せる。
 * bin/import-hytek.php（手で保存したフォルダ）と、管理画面の「最新の記録を取得」
 *（Fetch\LatestRecordsFetcher）の両方が使う。
 */
final class HyTekBundle
{
    /**
     * 大会フォルダの中の、結果を持つページ。目次や「最後に終わった種目」のページは外す。
     *
     * @return list<string> ファイル名順（= 日付 + 種目順）
     */
    public static function pagesIn(string $directory): array
    {
        $files = glob(rtrim($directory, '/') . '/*.htm*') ?: [];
        $files = array_values(array_filter(
            $files,
            static fn (string $f): bool => preg_match('/(evtindex|lastevt|index|main)\./i', basename($f)) !== 1
        ));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param list<string> $files
     * @return array{html: string, pages: int, skipped: int}
     */
    public static function combine(array $files): array
    {
        $extractor = new HtmlTextExtractor();
        $html = "<!-- DropTime: HyTekBundle が結合したページ -->\n<html><body>\n";
        $skipped = 0;

        foreach ($files as $file) {
            try {
                $text = $extractor->extract((string) file_get_contents($file));
            } catch (ImportException) {
                $skipped++; // <pre> の無いページ（メニュー等）は黙って飛ばす

                continue;
            }

            $html .= "<pre>\n" . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n</pre>\n";
        }

        return ['html' => $html . "</body></html>\n", 'pages' => count($files) - $skipped, 'skipped' => $skipped];
    }
}
