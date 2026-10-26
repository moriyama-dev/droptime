<?php

declare(strict_types=1);

use DropTime\Domain\EventTitle;
use DropTime\Domain\SwimTime;
use DropTime\Support\Lang;
use DropTime\Support\View;

/** @var array<string, mixed> $meet */
/** @var array<string, mixed> $event */
/** @var array<int, list<array<string, mixed>>> $heats */
/** @var list<array<string, mixed>> $results */
/** @var array<int, list<array<string, mixed>>> $splits */
/** @var array<int, true> $favoriteSwimmerIds */
/** @var DropTime\Support\View $view */
$e = static fn (mixed $v): string => View::e($v);
$t = static fn (string $k, mixed ...$a): string => View::e(Lang::t($k, ...$a));

$isFavorite = static fn (mixed $id): bool => $id !== null && isset($favoriteSwimmerIds[(int) $id]);

$displayName = static function (array $row) use ($e): string {
    if ($row['swimmer_name'] !== null) {
        return $e($row['swimmer_name']);
    }

    $team = $row['team_name'] ?? $row['team_code'] ?? DropTime\Support\Lang::t('event.relay_team');

    return $e($team . ($row['relay_letter'] !== null ? ' ' . $row['relay_letter'] : ''));
};
?>
<p class="breadcrumb"><a href="<?= $view->url('meets.show', ['meet' => (int) $meet['id']]) ?>">← <?= $e($meet['name']) ?></a></p>
<h1 class="page-title"><?= $t('evt.prefix') ?> <?= $e($event['event_number']) ?><?php if (EventTitle::roundLabel((string) $event['round']) !== '') : ?> <?= $e(EventTitle::roundLabel((string) $event['round'])) ?><?php endif; ?></h1>
<p class="meet-block__meta"><?= $e(EventTitle::of($event)) ?> ・ <?= $e($event['course']) ?></p>

<?php if ($results !== []) : ?>
    <section class="section">
        <h2 class="section__title"><?= $t('event.results') ?></h2>
        <ol class="entry-table" aria-label="<?= $t('event.results') ?>">
            <?php foreach ($results as $result) : ?>
                <?php $fav = $isFavorite($result['swimmer_id']); ?>
                <li class="entry-line<?= $fav ? ' entry-line--favorite' : '' ?>">
                    <span class="entry-line__rank time"><?= $result['place'] !== null ? $e($result['place']) : '—' ?></span>
                    <span class="entry-line__name">
                        <?php if ($result['swimmer_id'] !== null) : ?>
                            <a href="<?= $view->url('swimmers.show', ['swimmer' => (int) $result['swimmer_id']]) ?>"><?= $displayName($result) ?></a>
                        <?php else : ?>
                            <?= $displayName($result) ?>
                        <?php endif; ?>
                        <?php if ($result['team_name'] !== null && $result['swimmer_id'] !== null) : ?>
                            <span class="entry-line__team"><?= $e($result['team_name']) ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="entry-line__end">
                        <?php if ($result['status'] !== 'OK') : ?>
                            <span class="entry-line__status"><?= $e($result['status']) ?></span>
                        <?php else : ?>
                            <span class="time entry-line__time"><?= $e(SwimTime::format($result['final_time_cs'] !== null ? (int) $result['final_time_cs'] : null)) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php $resultSplits = $splits[(int) $result['id']] ?? []; ?>
                    <?php if ($resultSplits !== []) : ?>
                        <details class="splits">
                            <summary class="splits__summary"><?= $t('event.splits') ?></summary>
                            <table class="splits__table">
                                <thead><tr><th><?= $t('event.sp_dist') ?></th><th><?= $t('event.sp_cum') ?></th><th><?= $t('event.sp_leg') ?></th></tr></thead>
                                <tbody>
                                    <?php $previous = 0; ?>
                                    <?php foreach ($resultSplits as $split) : ?>
                                        <tr>
                                            <td class="time"><?= $e($split['distance']) ?>m</td>
                                            <td class="time"><?= $e(SwimTime::format((int) $split['cumulative_time_cs'])) ?></td>
                                            <td class="time"><?= $e(SwimTime::format((int) $split['cumulative_time_cs'] - $previous)) ?></td>
                                        </tr>
                                        <?php $previous = (int) $split['cumulative_time_cs']; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endif; ?>
                    <?php if ($result['status'] === 'DQ' && $result['dq_reason'] !== null) : ?>
                        <span class="entry-line__dq"><?= $e($result['dq_reason']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<?php if ($heats !== []) : ?>
    <section class="section">
        <h2 class="section__title"><?= $t('event.heats') ?></h2>
        <?php foreach ($heats as $heatNumber => $entries) : ?>
            <h3 class="heat-title"><?= $heatNumber > 0 ? $t('event.heat_n', $heatNumber) : $t('event.heat_unknown') ?></h3>
            <ol class="entry-table" aria-label="<?= $t('event.heats') ?>">
                <?php foreach ($entries as $entry) : ?>
                    <?php $fav = $isFavorite($entry['swimmer_id']); ?>
                    <li class="entry-line<?= $fav ? ' entry-line--favorite' : '' ?>">
                        <span class="entry-line__rank time"><?= $entry['lane'] !== null ? $e($entry['lane']) : '—' ?></span>
                        <span class="entry-line__name">
                            <?php if ($entry['swimmer_id'] !== null) : ?>
                                <a href="<?= $view->url('swimmers.show', ['swimmer' => (int) $entry['swimmer_id']]) ?>"><?= $displayName($entry) ?></a>
                            <?php else : ?>
                                <?= $displayName($entry) ?>
                            <?php endif; ?>
                            <?php if ($entry['team_name'] !== null && $entry['swimmer_id'] !== null) : ?>
                                <span class="entry-line__team"><?= $e($entry['team_name']) ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="entry-line__end">
                            <?php if ($entry['seed_is_ntime']) : ?>
                                <span class="entry-line__status">NT</span>
                            <?php elseif ($entry['seed_time_cs'] !== null) : ?>
                                <span class="time entry-line__time"><?= $e(SwimTime::format((int) $entry['seed_time_cs'])) ?></span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endforeach; ?>
    </section>
<?php elseif ($results === []) : ?>
    <p class="empty"><?= $t('event.none') ?></p>
<?php endif; ?>
