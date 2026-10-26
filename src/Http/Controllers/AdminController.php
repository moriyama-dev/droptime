<?php

declare(strict_types=1);

namespace DropTime\Http\Controllers;

use DropTime\Domain\Course;
use DropTime\Fetch\FetchJob;
use DropTime\Http\Controller;
use DropTime\Http\Flash;
use DropTime\Http\HttpException;
use DropTime\Http\Request;
use DropTime\Http\Response;
use DropTime\Import\ImportException;
use DropTime\Import\ImportRepository;
use DropTime\Import\ImportService;
use DropTime\Import\UploadValidator;
use DropTime\Matching\MergeService;
use DropTime\Support\Clock;
use DropTime\Support\Lang;

/**
 * 管理画面。取り込みフロー（アップロード → 確認 → 投入）と、
 * 速報サイトからの自動取得（最新の記録を取得）をここで扱う。
 * 到達には保護者の合言葉に加えて管理者の合言葉が必要（Access::ADMIN）。
 */
final class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        $repository = new ImportRepository($this->context->db);

        $meets = $this->context->db->select(
            'SELECT m.id, m.name, m.start_date, m.course,'
            . ' (SELECT COUNT(*) FROM events e WHERE e.meet_id = m.id) AS event_count'
            . ' FROM meets m ORDER BY m.start_date DESC, m.id DESC LIMIT 20'
        );

        $pendingMerges = (int) $this->context->db->selectValue(
            'SELECT COUNT(*) FROM swimmer_merge_candidates WHERE status = :status',
            ['status' => 'pending']
        );

        // 出典の証拠が 1 件も無い大会。新しい大会を取り込んだら撮り忘れないように出す。
        $evidence = \DropTime\Evidence\EvidenceStore::fromConfig();
        $covered = [];

        foreach ($evidence->all() as $capture) {
            foreach ((array) ($capture['meet_ids'] ?? []) as $meetId) {
                $covered[(int) $meetId] = true;
            }
        }

        $unproven = array_values(array_filter($meets, static fn (array $m): bool => !isset($covered[(int) $m['id']])));

        $fetch = FetchJob::fromConfig();

        return $this->view('admin/home', [
            'fetchRunning' => $fetch->isRunning(),
            'fetchStatus' => $fetch->status(),
            'unproven' => $unproven,
            'imports' => $repository->recent(),
            'meets' => $meets,
            'pendingMerges' => $pendingMerges,
            'flash' => Flash::pull(),
        ]);
    }

    /**
     * 「最新の記録を取得」。速報サイトを見に行く処理（bin/fetch-latest.php）を裏で起動し、
     * すぐに進み具合の画面へ移る。取得は数分かかるので Web のリクエストでは待たない。
     */
    public function fetchStart(Request $request): Response
    {
        $job = FetchJob::fromConfig();

        if ($job->isRunning()) {
            Flash::error(Lang::t('fetch.already'));
        } elseif (!$job->startInBackground()) {
            Flash::error(Lang::t('fetch.cannot_start'));

            return $this->redirectToRoute('admin.home');
        }

        return $this->redirectToRoute('admin.fetch');
    }

    public function fetchShow(Request $request): Response
    {
        $job = FetchJob::fromConfig();

        return $this->view('admin/fetch', [
            'running' => $job->isRunning(),
            'status' => $job->status(),
            'log' => $job->logTail(60),
            'flash' => Flash::pull(),
        ]);
    }

    public function upload(Request $request): Response
    {
        $file = $request->files['file'] ?? null;

        if (!is_array($file)) {
            Flash::error(Lang::t('fl.file_missing'));

            return $this->redirectToRoute('admin.home');
        }

        try {
            $uploaded = (new UploadValidator())->validate($file);
            $id = ImportService::create($this->context->db)->receive($uploaded);
        } catch (ImportException $e) {
            Flash::error($e->getMessage());

            return $this->redirectToRoute('admin.home');
        }

        return $this->redirectToRoute('admin.imports.show', ['import' => $id]);
    }

    public function importShow(Request $request): Response
    {
        $row = $this->requireImport($request);

        return $this->view('admin/import-show', [
            'import' => $row,
            'summary' => ImportRepository::summaryOf($row),
            'flash' => Flash::pull(),
        ]);
    }

    public function importCommit(Request $request): Response
    {
        $row = $this->requireImport($request);
        $id = (int) $row['id'];

        $meet = [
            'name' => trim($request->postString('meet_name')),
            'start_date' => trim($request->postString('start_date')),
            'end_date' => trim($request->postString('end_date')),
            'course' => trim($request->postString('course')),
            'host' => trim($request->postString('host')),
            'venue' => trim($request->postString('venue')),
        ];

        $error = self::validateMeet($meet);

        if ($error !== null) {
            Flash::error($error);

            return $this->redirectToRoute('admin.imports.show', ['import' => $id]);
        }

        try {
            $stats = ImportService::create($this->context->db)->commit($id, $meet);
        } catch (ImportException $e) {
            Flash::error($e->getMessage());

            return $this->redirectToRoute('admin.imports.show', ['import' => $id]);
        }

        Flash::success(Lang::t('fl.committed', $stats['events'], $stats['entries'], $stats['results']));

        return $this->redirectToRoute('admin.imports.show', ['import' => $id]);
    }

    public function importDiscard(Request $request): Response
    {
        $row = $this->requireImport($request);

        try {
            ImportService::create($this->context->db)->discard((int) $row['id']);
        } catch (ImportException $e) {
            Flash::error($e->getMessage());

            return $this->redirectToRoute('admin.imports.show', ['import' => (int) $row['id']]);
        }

        Flash::success(Lang::t('fl.discarded'));

        return $this->redirectToRoute('admin.home');
    }

    public function importReparse(Request $request): Response
    {
        $row = $this->requireImport($request);

        try {
            ImportService::create($this->context->db)->reparse((int) $row['id']);
            Flash::success(Lang::t('fl.reparsed'));
        } catch (ImportException $e) {
            Flash::error($e->getMessage());
        }

        return $this->redirectToRoute('admin.imports.show', ['import' => (int) $row['id']]);
    }

    /**
     * 統合候補の判定画面。保留中の候補と、取り消せる統合の履歴を出す。
     */
    public function merges(Request $request): Response
    {
        $candidates = $this->context->db->select(
            'SELECT c.id, c.reason, c.created_at,'
            . ' a.id AS a_id, a.full_name AS a_name, a.birth_year AS a_birth, ta.name AS a_team,'
            . ' (SELECT COUNT(*) FROM results r WHERE r.swimmer_id = a.id) AS a_results,'
            . ' b.id AS b_id, b.full_name AS b_name, b.birth_year AS b_birth, tb.name AS b_team,'
            . ' (SELECT COUNT(*) FROM results r WHERE r.swimmer_id = b.id) AS b_results'
            . ' FROM swimmer_merge_candidates c'
            . ' JOIN swimmers a ON a.id = c.swimmer_id'
            . ' JOIN swimmers b ON b.id = c.candidate_id'
            . ' LEFT JOIN teams ta ON ta.id = a.team_id'
            . ' LEFT JOIN teams tb ON tb.id = b.team_id'
            . ' WHERE c.status = :status ORDER BY c.created_at, c.id',
            ['status' => 'pending']
        );

        $merges = $this->context->db->select(
            'SELECT m.*, s.full_name AS kept_name FROM swimmer_merges m'
            . ' JOIN swimmers s ON s.id = m.kept_id'
            . ' ORDER BY m.id DESC LIMIT 20'
        );

        return $this->view('admin/merges', [
            'candidates' => $candidates,
            'merges' => $merges,
            'flash' => Flash::pull(),
        ]);
    }

    public function mergeCommit(Request $request): Response
    {
        $candidate = $this->requireCandidate($request);

        // keep=a なら「新しく作られた側」を残す。既定は既存（b）を残す。
        $keepA = $request->postString('keep') === 'a';
        $keepId = (int) ($keepA ? $candidate['swimmer_id'] : $candidate['candidate_id']);
        $removeId = (int) ($keepA ? $candidate['candidate_id'] : $candidate['swimmer_id']);

        try {
            (new MergeService($this->context->db))->merge($keepId, $removeId);
        } catch (ImportException $e) {
            Flash::error($e->getMessage());

            return $this->redirectToRoute('admin.merges');
        }

        Flash::success(Lang::t('fl.merged'));

        return $this->redirectToRoute('admin.merges');
    }

    public function mergeDismiss(Request $request): Response
    {
        $candidate = $this->requireCandidate($request);

        $this->context->db->execute(
            'UPDATE swimmer_merge_candidates SET status = :status, resolved_at = :at WHERE id = :id',
            ['status' => 'dismissed', 'at' => Clock::utcNow(), 'id' => (int) $candidate['id']]
        );

        Flash::success(Lang::t('fl.dismissed'));

        return $this->redirectToRoute('admin.merges');
    }

    public function mergeUndo(Request $request): Response
    {
        try {
            (new MergeService($this->context->db))->undo($request->intParam('merge'));
        } catch (ImportException $e) {
            Flash::error($e->getMessage());

            return $this->redirectToRoute('admin.merges');
        }

        Flash::success(Lang::t('fl.undone'));

        return $this->redirectToRoute('admin.merges');
    }

    /** @return array<string, mixed> */
    private function requireCandidate(Request $request): array
    {
        $row = $this->context->db->selectOne(
            'SELECT * FROM swimmer_merge_candidates WHERE id = :id AND status = :status',
            ['id' => $request->intParam('candidate'), 'status' => 'pending']
        );

        if ($row === null) {
            throw HttpException::notFound('統合候補が見つかりません。');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function requireImport(Request $request): array
    {
        $row = (new ImportRepository($this->context->db))->find($request->intParam('import'));

        if ($row === null) {
            throw HttpException::notFound('取り込みが見つかりません。');
        }

        return $row;
    }

    /** @param array<string, string> $meet */
    private static function validateMeet(array $meet): ?string
    {
        if ($meet['name'] === '') {
            return Lang::t('imp.v_name');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $meet['start_date']) !== 1) {
            return Lang::t('imp.v_start');
        }

        if ($meet['end_date'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $meet['end_date']) !== 1) {
            return Lang::t('imp.v_end');
        }

        if (Course::tryFrom($meet['course']) === null) {
            return Lang::t('imp.v_course');
        }

        return null;
    }
}
