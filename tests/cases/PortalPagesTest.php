<?php

declare(strict_types=1);

use DropTime\Fetch\PortalPages;
use DropTime\Tests\Assert;

/*
 * 速報サイトのページの読み取り。HTML は results.swimmeets.ca の実物を縮めたもの。
 */

$root = <<<'HTML'
<tr><td data-sort="*&#039;2026crdiv1lc"><a href="/%272026crdiv1lc/"><img class="icon" src="/_autoindex/assets/icons/folder-fill.svg" alt="Directory">&#039;2026crdiv1lc</a></td><td data-sort="204855463">2026-06-17 00:17</td><td data-sort="-1">-</td></tr>
<tr><td data-sort="*2025 cr div 2 lc"><a href="/2025%20CR%20Div%202%20LC/"><img class="icon" src="/x.svg" alt="Directory">2025 CR Div 2 LC</a></td><td data-sort="174406313">2025-06-29 14:11</td><td data-sort="-1">-</td></tr>
<tr><td data-sort="*2026hof"><a href="/2026hof/"><img class="icon" src="/x.svg" alt="Directory">2026hof</a></td><td data-sort="215541328">2026-10-18 16:35</td><td data-sort="-1">-</td></tr>
<tr><td><a href="/../etc/">bad</a></td><td data-sort="1">2026-10-18 16:35</td></tr>
HTML;

$evtIndex = <<<'HTML'
<h2 align="center"><font color="#CC0033">2026 York Invitational - Revised  </h2></font>
<p align="center">10/31/2026 - 11/01/2026<br>
<a href="261031lastevt.htm" target=main><font color="#0000FF">Latest Completed Event </a><br>
<a href="261031F002.htm" target=main>#2 Boys 11&O 200 IM </a><br>
<a href="261031F001.htm" target=main>#1 Girls 11&O 200 IM </a><br>
<a href="main.htm">main</a>
HTML;

$psych = <<<'TXT'
<pre>
          2026 York Invitational - Revised - 10/31/2026 to 11/01/2026
                                  Psych Sheet
Event 9  Girls 100 SC Meter Freestyle
===============================================================================
     Name                         Age Team
===============================================================================
  1  Zu, Salarik                   17 YORK-CA                   1:01.86
</pre>
TXT;

$results = <<<'TXT'
<pre>
<b> Event 1  Girls 13-14 200 LC Meter Freestyle</b>
==================================================================
    Name                  Age Team              Prelims     Finals
==================================================================
  1 Tos, Zamaka            13 ZORC              2:20.11     2:18.40
</pre>
TXT;

return [
    'ルートの一覧から大会フォルダと更新日時を読む' => static function () use ($root): void {
        $folders = PortalPages::folders($root);

        Assert::same(3, count($folders), '危ないパスは捨てる');
        Assert::same('%272026crdiv1lc', $folders[0]['folder'], 'URL エンコードのまま持つ');
        Assert::same('2026-06-17 00:17', $folders[0]['modified']);
        Assert::same('2025%20CR%20Div%202%20LC', $folders[1]['folder']);
    },
    'フォルダ名として安全なものだけ通す' => static function (): void {
        Assert::true(PortalPages::isSafeFolder('2026hof'));
        Assert::true(PortalPages::isSafeFolder('25tsc10%26u'));
        Assert::false(PortalPages::isSafeFolder('..'));
        Assert::false(PortalPages::isSafeFolder('a/b'));
    },
    '目次から大会名・日程・種目ページを読む' => static function () use ($evtIndex): void {
        $index = PortalPages::eventIndex($evtIndex);

        Assert::same('2026 York Invitational - Revised', $index['name']);
        Assert::same('2026-10-31', $index['start']);
        Assert::same('2026-11-01', $index['end']);
        Assert::same(['261031F001.htm', '261031F002.htm'], $index['pages'], '目次・main は外して順に並べる');
        Assert::same('261031lastevt.htm', $index['last_event']);
    },
    'Psych Sheet は結果ではない' => static function () use ($psych, $results): void {
        Assert::false(PortalPages::isResultsPage($psych), 'エントリータイムを結果として取り込まない');
        Assert::true(PortalPages::isResultsPage($results));
    },
    'チーム欄のクラブコードを見つける' => static function () use ($results, $psych): void {
        Assert::true(PortalPages::mentionsClub($results, 'ZORC'));
        Assert::true(PortalPages::mentionsClub('  1 Tos, Zamaka  13 ZORC-ON  2:18.40', 'zorc'), '州コード付きも拾う');
        Assert::false(PortalPages::mentionsClub($psych, 'ZORC'));
        Assert::false(PortalPages::mentionsClub('Swim Zorcian Club', 'ZORC'), '語の一部には反応しない');
    },
];
