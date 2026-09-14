<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/student_auth.php';
require_once __DIR__ . '/../../includes/cases_service.php';
require_once __DIR__ . '/../../includes/specialties_service.php';

require_student();
$student = current_student();
$studentId = (string) $student['id'];

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'add') {
        $created = add_case($studentId, [
            'case_date'         => (string) ($_POST['case_date']         ?? ''),
            'specialty_id'      => (string) ($_POST['specialty_id']      ?? ''),
            'procedure'         => (string) ($_POST['procedure']         ?? ''),
            'doctor'            => (string) ($_POST['doctor']            ?? ''),
            'doctor_rating'     => (int)    ($_POST['doctor_rating']     ?? 0),
            'doctor_comment'    => (string) ($_POST['doctor_comment']    ?? ''),
            'preceptor'         => (string) ($_POST['preceptor']         ?? ''),
            'preceptor_rating'  => (int)    ($_POST['preceptor_rating']  ?? 0),
            'preceptor_comment' => (string) ($_POST['preceptor_comment'] ?? ''),
            'role'              => (string) ($_POST['role']              ?? ''),
            'notes'             => (string) ($_POST['notes']             ?? ''),
        ]);
        $message = $created ? 'Case logged.' : '';
        if (!$created) $error = 'Fill in date, specialty, procedure, and role.';
    } elseif ($action === 'update') {
        $id = (string) ($_POST['id'] ?? '');
        $existing = find_case($id);
        if (!$existing || (string) ($existing['student_id'] ?? '') !== $studentId) {
            $error = 'Case not found.';
        } else {
            update_case($id, [
                'case_date'         => (string) ($_POST['case_date']         ?? ''),
                'specialty_id'      => (string) ($_POST['specialty_id']      ?? ''),
                'procedure'         => (string) ($_POST['procedure']         ?? ''),
                'doctor'            => (string) ($_POST['doctor']            ?? ''),
                'doctor_rating'     => (int)    ($_POST['doctor_rating']     ?? 0),
                'doctor_comment'    => (string) ($_POST['doctor_comment']    ?? ''),
                'preceptor'         => (string) ($_POST['preceptor']         ?? ''),
                'preceptor_rating'  => (int)    ($_POST['preceptor_rating']  ?? 0),
                'preceptor_comment' => (string) ($_POST['preceptor_comment'] ?? ''),
                'role'              => (string) ($_POST['role']              ?? ''),
                'notes'             => (string) ($_POST['notes']             ?? ''),
            ]);
            $message = 'Case updated.';
        }
    } elseif ($action === 'delete') {
        $id = (string) ($_POST['id'] ?? '');
        $existing = find_case($id);
        if ($existing && (string) ($existing['student_id'] ?? '') === $studentId) {
            delete_case($id);
            $message = 'Case removed.';
        }
    }
    // After a successful add, stay on the add form for rapid entry —
    // logging N cases in a row should be one form, one tap, repeat.
    // Errors, edits, and deletes go back to the case list.
    if ($action === 'add' && $message !== '') {
        header('Location: /students/cases.php?add=1&msg=' . urlencode($message));
    } else {
        header('Location: /students/cases.php?msg=' . urlencode($message ?: $error));
    }
    exit;
}

$cases = cases_for_student($studentId);
$specialties = load_specialties();
$specById = [];
foreach ($specialties as $s) $specById[(string) $s['id']] = $s;

// Site-wide doctor + preceptor lists (auto-populated as students type new
// names — Heather can normalize in admin). Every student's autocomplete
// draws from the same set, so 'Dr. Chen' stays 'Dr. Chen' across the cohort.
$doctorsList = load_doctors();
$preceptorsList = load_preceptors();
$doctorById = doctors_by_id();
$preceptorById = preceptors_by_id();

$msg = (string) ($_GET['msg'] ?? '');
$showAdd = isset($_GET['add']) || isset($_GET['edit']);
$editingCase = null;
if (isset($_GET['edit'])) {
    $editingCase = find_case((string) $_GET['edit']);
    if ($editingCase && (string) ($editingCase['student_id'] ?? '') !== $studentId) $editingCase = null;
    $showAdd = $editingCase !== null;
}
$formDefaultDate = date('Y-m-d');

$pageTitle = 'My cases';
$activeNav = 'cases';
$extraHead = <<<'CSS'
<style>
    /* Mobile-first chip buttons for the case form. Tap-target ≥ 44px,
       full-row wrap so a thumb can hit any one without aiming. */
    .chip-row { display: flex; flex-wrap: wrap; gap: .4rem; }
    .chip-row .btn-check + .btn {
        border-radius: 999px;
        padding: .55rem 1.1rem;
        font-weight: 500;
        border-width: 2px;
        white-space: normal;
        min-height: 44px;
    }
    .chip-row .btn-check:checked + .btn {
        background: var(--sc-primary);
        border-color: var(--sc-primary);
        color: #fff;
    }
    .role-chip.btn-outline-primary { color: var(--sc-primary); border-color: var(--sc-primary); }
    .role-chip.btn-check:checked + .btn { background: var(--sc-primary); }
    .sticky-submit {
        position: sticky;
        bottom: 0;
        background: linear-gradient(180deg, rgba(244,247,251,0), var(--sc-bg) 40%);
        padding: 1rem 0 .5rem;
        z-index: 5;
    }

    /* 5-star rating picker. Simple button-per-star wiring a hidden input
       via JS — this replaces an earlier CSS-only radio version that was
       fragile on some mobile browsers (the absolute-positioned hidden
       radios silently failed to persist a tap). Hidden input always
       submits, so 'clear' actually clears. */
    .star-picker { display: inline-flex; gap: .1rem; align-items: center; }
    .star-picker .star-btn {
        background: transparent;
        border: 0;
        font-size: 1.9rem;
        line-height: 1;
        color: #d1d5db;
        padding: .1rem .25rem;
        cursor: pointer;
        transition: color .1s;
    }
    .star-picker .star-btn.lit { color: #f0a500; }
    .star-picker .star-btn:hover,
    .star-picker .star-btn:focus-visible { color: #f4a800; outline: none; }
    .star-clear {
        font-size: .8rem;
        color: #6b7280;
        text-decoration: underline;
        background: none;
        border: 0;
        padding: 0 .5rem;
        cursor: pointer;
    }
</style>
CSS;
require_once __DIR__ . '/../../includes/student_header.php';
?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
        <h1 class="h4 mb-0">My cases <small class="text-muted">(<?= count($cases) ?>)</small></h1>
        <?php if (!$showAdd): ?>
            <a href="/students/cases.php?add=1" class="btn btn-scrub">+ Log a new case</a>
        <?php endif; ?>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if ($showAdd):
        $editingDoctorName = $editingCase && !empty($editingCase['doctor_id'])
            ? (string) ($doctorById[(string) $editingCase['doctor_id']]['name'] ?? '')
            : '';
        $editingPreceptorName = $editingCase && !empty($editingCase['preceptor_id'])
            ? (string) ($preceptorById[(string) $editingCase['preceptor_id']]['name'] ?? '')
            : '';
        $currentSpecialtyId = $editingCase ? (string) $editingCase['specialty_id'] : '';
        $currentRole = $editingCase ? (string) $editingCase['role'] : 'first_scrub';
        $hasOptionalDetails = !empty($editingDoctorName) || !empty($editingPreceptorName)
            || !empty($editingCase['notes'] ?? '');
    ?>
        <div class="card mb-4">
            <div class="card-header"><strong><?= $editingCase ? 'Edit case' : 'Log a new case' ?></strong></div>
            <div class="card-body">
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="<?= $editingCase ? 'update' : 'add' ?>">
                    <?php if ($editingCase): ?>
                        <input type="hidden" name="id" value="<?= htmlspecialchars((string) $editingCase['id']) ?>">
                    <?php endif; ?>

                    <!-- Procedure — the one field they always type -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Procedure <span class="text-danger">*</span></label>
                        <input type="text" name="procedure" class="form-control form-control-lg" required
                            value="<?= htmlspecialchars((string) ($editingCase['procedure'] ?? '')) ?>"
                            placeholder="e.g. Laparoscopic Cholecystectomy" autofocus>
                    </div>

                    <!-- Specialty as tap chips -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold d-block">Specialty <span class="text-danger">*</span></label>
                        <div class="chip-row" role="radiogroup">
                            <?php foreach ($specialties as $s):
                                $sid = htmlspecialchars((string) $s['id']);
                                $checked = $currentSpecialtyId === (string) $s['id'] ? 'checked' : '';
                            ?>
                                <input type="radio" class="btn-check" name="specialty_id" id="sp-<?= $sid ?>"
                                    value="<?= $sid ?>" <?= $checked ?> required>
                                <label class="btn btn-outline-primary" for="sp-<?= $sid ?>">
                                    <?= htmlspecialchars((string) $s['name']) ?>
                                    <?php if (!empty($s['is_general'])): ?>
                                        <span class="opacity-75 small">(gen)</span>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Role as big pill chips -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold d-block">My role <span class="text-danger">*</span></label>
                        <div class="chip-row" role="radiogroup">
                            <?php foreach (CASE_ROLES as $r):
                                $checked = $currentRole === $r ? 'checked' : '';
                            ?>
                                <input type="radio" class="btn-check role-chip" name="role" id="role-<?= $r ?>"
                                    value="<?= $r ?>" <?= $checked ?> required>
                                <label class="btn btn-outline-primary" for="role-<?= $r ?>">
                                    <?= htmlspecialchars(role_label($r)) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Date — defaults to today -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                        <input type="date" name="case_date" class="form-control" required
                            value="<?= htmlspecialchars($editingCase['case_date'] ?? $formDefaultDate) ?>">
                    </div>

                    <!-- Optional details — collapsed by default so the form fits in one screen -->
                    <?php
                        $currentDoctorRating    = (int) ($editingCase['doctor_rating']    ?? 0);
                        $currentPreceptorRating = (int) ($editingCase['preceptor_rating'] ?? 0);
                        $hasOptionalDetails = $hasOptionalDetails
                            || $currentDoctorRating > 0 || $currentPreceptorRating > 0
                            || !empty($editingCase['doctor_comment']) || !empty($editingCase['preceptor_comment']);

                        // 5-star picker: button-per-star + a hidden input the JS
                        // updates on tap. Hidden input always submits, so
                        // update_case can tell 0 (cleared) from "unchanged".
                        $starPicker = function (string $fieldName, int $current) {
                            $current = max(0, min(5, $current));
                            $hiddenId = 'h-' . str_replace('_', '-', $fieldName);
                            $out = sprintf(
                                '<input type="hidden" name="%s" id="%s" value="%d">',
                                htmlspecialchars($fieldName), htmlspecialchars($hiddenId), $current
                            );
                            $out .= sprintf('<div class="star-picker" data-target="%s">', htmlspecialchars($hiddenId));
                            for ($v = 1; $v <= 5; $v++) {
                                $lit = $v <= $current ? ' lit' : '';
                                $out .= sprintf(
                                    '<button type="button" class="star-btn%s" data-value="%d" title="%d star%s" aria-label="%d star%s">★</button>',
                                    $lit, $v, $v, $v === 1 ? '' : 's', $v, $v === 1 ? '' : 's'
                                );
                            }
                            $out .= '<button type="button" class="star-clear" data-value="0">clear</button>';
                            $out .= '</div>';
                            return $out;
                        };
                    ?>
                    <details class="mb-3"<?= $hasOptionalDetails ? ' open' : '' ?>>
                        <summary class="fw-semibold" style="cursor:pointer; padding:.5rem 0;">
                            Doctor · Preceptor · Notes <small class="text-muted">(optional)</small>
                        </summary>
                        <div class="mt-2">
                            <div class="mb-3">
                                <label class="form-label">Doctor</label>
                                <input type="text" name="doctor" class="form-control" list="doctors-list"
                                    value="<?= htmlspecialchars($editingDoctorName) ?>"
                                    placeholder="Pick from the list or type a new one">
                                <datalist id="doctors-list">
                                    <?php foreach ($doctorsList as $d): ?>
                                        <option value="<?= htmlspecialchars((string) $d['name']) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
                                    <span class="small text-muted">Rate them:</span>
                                    <?= $starPicker('doctor_rating', $currentDoctorRating) ?>
                                </div>
                                <textarea name="doctor_comment" class="form-control form-control-sm mt-2"
                                    rows="1" placeholder="Comment about this doctor (optional)"><?= htmlspecialchars((string) ($editingCase['doctor_comment'] ?? '')) ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Preceptor</label>
                                <input type="text" name="preceptor" class="form-control" list="preceptors-list"
                                    value="<?= htmlspecialchars($editingPreceptorName) ?>"
                                    placeholder="Pick from the list or type a new one">
                                <datalist id="preceptors-list">
                                    <?php foreach ($preceptorsList as $p): ?>
                                        <option value="<?= htmlspecialchars((string) $p['name']) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
                                    <span class="small text-muted">Rate them:</span>
                                    <?= $starPicker('preceptor_rating', $currentPreceptorRating) ?>
                                </div>
                                <textarea name="preceptor_comment" class="form-control form-control-sm mt-2"
                                    rows="1" placeholder="Comment about this preceptor (optional)"><?= htmlspecialchars((string) ($editingCase['preceptor_comment'] ?? '')) ?></textarea>
                                <small class="text-muted d-block mt-1">Ratings + comments help Heather pick end-of-year awards. New doctor/preceptor names get added to her list automatically.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Notes <small class="text-muted">(no patient info please)</small></label>
                                <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars((string) ($editingCase['notes'] ?? '')) ?></textarea>
                            </div>
                        </div>
                    </details>
                    <script>
                    // Star picker — tap a star (or "clear") to set the value
                    // on the hidden input the picker points at. Repaints all
                    // buttons so the correct number are lit.
                    document.querySelectorAll('.star-picker').forEach(picker => {
                        const targetId = picker.dataset.target;
                        const hidden = document.getElementById(targetId);
                        if (!hidden) return;
                        function paint() {
                            const current = parseInt(hidden.value, 10) || 0;
                            picker.querySelectorAll('.star-btn').forEach(btn => {
                                const v = parseInt(btn.dataset.value, 10);
                                btn.classList.toggle('lit', v <= current);
                            });
                        }
                        picker.querySelectorAll('[data-value]').forEach(btn => {
                            btn.addEventListener('click', () => {
                                hidden.value = String(parseInt(btn.dataset.value, 10) || 0);
                                paint();
                            });
                        });
                        paint();
                    });
                    </script>

                    <div class="sticky-submit d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-scrub btn-lg flex-grow-1">
                            <?= $editingCase ? 'Save changes' : 'Log case & add another' ?>
                        </button>
                        <a href="/students/cases.php" class="btn btn-outline-secondary btn-lg"><?= $editingCase ? 'Cancel' : 'Done' ?></a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($cases === []): ?>
        <div class="card"><div class="card-body text-muted">No cases logged yet. Hit <strong>+ Log a new case</strong> to add your first.</div></div>
    <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-striped mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Procedure</th>
                            <th>Specialty</th>
                            <th>Role</th>
                            <th>Doctor</th>
                            <th>Preceptor</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cases as $c):
                            $sp = $specById[(string) ($c['specialty_id'] ?? '')] ?? null;
                            $cid = htmlspecialchars((string) $c['id']);
                        ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $c['case_date']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars((string) $c['procedure']) ?></strong>
                                    <?php if (!empty($c['notes'])): ?>
                                        <div class="small text-muted"><?= nl2br(htmlspecialchars((string) $c['notes'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= $sp ? htmlspecialchars((string) $sp['name']) : '<span class="text-danger">unset</span>' ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars(role_label((string) $c['role'])) ?></span></td>
                                <td class="small text-muted">
                                    <?php
                                        $dId = (string) ($c['doctor_id'] ?? '');
                                        $dName = $dId !== '' ? (string) ($doctorById[$dId]['name'] ?? '(deleted)') : '';
                                        echo htmlspecialchars($dName);
                                    ?>
                                </td>
                                <td class="small text-muted">
                                    <?php
                                        $pId = (string) ($c['preceptor_id'] ?? '');
                                        $pName = $pId !== '' ? (string) ($preceptorById[$pId]['name'] ?? '(deleted)') : '';
                                        echo htmlspecialchars($pName);
                                    ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="/students/cases.php?edit=<?= $cid ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this case?')">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $cid ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">×</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../includes/student_footer.php'; ?>
