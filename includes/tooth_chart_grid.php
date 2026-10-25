<?php
/**
 * tooth_chart_grid.php — Boxed tooth chart that copies the Amparo Dental Clinic paper form.
 *
 * Paper layout (7 rows x 16 columns, boxes share borders):
 *
 *   row 1   [ note box ]      E D C B A | A B C D E   (primary upper — note boxes)
 *   row 2   [ letters  ]      E D C B A | A B C D E   (primary upper)
 *   row 3   [ note box ] 16 boxes, one per UPPER permanent tooth
 *   row 4   8 7 6 5 4 3 2 1 | 1 2 3 4 5 6 7 8         (shared number row — label only)
 *   row 5   [ note box ] 16 boxes, one per LOWER permanent tooth
 *   row 6   [ letters  ]      E D C B A | A B C D E   (primary lower)
 *   row 7   [ note box ]      E D C B A | A B C D E   (primary lower — note boxes)
 *
 * Teeth 8-7-6 / 6-7-8 stick out on each side; primary teeth (A-E) only sit over/under 5-1 | 1-5.
 *
 * TWO WAYS TO USE THE BOXES (input mode has a switch above the chart)
 *   "Tag teeth"   — click a box to tag that tooth (coloured + check mark). Saved in tooth_number.
 *                   Permanent tooth: box ABOVE the number = upper tooth, BELOW = lower tooth.
 *                   Primary tooth:   its letter box or the empty box next to it.
 *   "Write marks" — type a short mark (X, P, ...) straight into any empty box, like writing
 *                   on the paper form. Saved in tooth_marks (JSON: {"16":"X","46":"P"}).
 *
 * Internally teeth are still FDI codes (18..11, 21..28, 48..41, 31..38, 55..51, 61..65, 85..81, 71..75).
 *
 * MODES
 *   mode = 'input'   (default)  → interactive, syncs to hidden inputs
 *   mode = 'display'            → read-only
 *
 * VARIABLES the parent defines BEFORE including:
 *   input   : $tc_input_name ('tooth_number'), $ts_select_id, $tc_initial, $chart_marks (optional)
 *             $tc_marks_name ('tooth_marks') — name of the hidden field that carries the marks
 *   display : $chart_teeth (FDI => status), $chart_marks (FDI => text), $chart_uid
 */

$tc_mode        = $tc_mode        ?? 'input';
$tc_input_name  = $tc_input_name  ?? 'tooth_number';
$tc_marks_name  = $tc_marks_name  ?? 'tooth_marks';
$ts_select_id   = $ts_select_id   ?? 'tooth_status_select';
$tc_initial     = $tc_initial     ?? '';
$chart_teeth    = $chart_teeth    ?? [];
$chart_marks    = (isset($chart_marks) && is_array($chart_marks)) ? $chart_marks : [];
$chart_uid      = $chart_uid      ?? 'chart_' . uniqid();
$tc_hide_legend = $tc_hide_legend ?? false;

// ── Tooth layout (left → right as printed on the paper form) ─────────────────
$upper_perm = [18,17,16,15,14,13,12,11, 21,22,23,24,25,26,27,28];
$lower_perm = [48,47,46,45,44,43,42,41, 31,32,33,34,35,36,37,38];
$upper_prim = ['55','54','53','52','51', '61','62','63','64','65'];
$lower_prim = ['85','84','83','82','81', '71','72','73','74','75'];

$primary_labels = [
  '55'=>'E','54'=>'D','53'=>'C','52'=>'B','51'=>'A',
  '61'=>'A','62'=>'B','63'=>'C','64'=>'D','65'=>'E',
  '85'=>'E','84'=>'D','83'=>'C','82'=>'B','81'=>'A',
  '71'=>'A','72'=>'B','73'=>'C','74'=>'D','75'=>'E',
];

$status_colors = [
  'normal'     => ['bg'=>'#BBF7D0','border'=>'#22C55E','text'=>'#14532D'],
  'caries'     => ['bg'=>'#FDE68A','border'=>'#F59E0B','text'=>'#78350F'],
  'filling'    => ['bg'=>'#BFDBFE','border'=>'#3B82F6','text'=>'#1E3A8A'],
  'extraction' => ['bg'=>'#FECACA','border'=>'#EF4444','text'=>'#7F1D1D'],
  'missing'    => ['bg'=>'#E5E7EB','border'=>'#6B7280','text'=>'#374151'],
  'crown'      => ['bg'=>'#DDD6FE','border'=>'#7C3AED','text'=>'#3B0764'],
  'rootcanal'  => ['bg'=>'#FFEDD5','border'=>'#F97316','text'=>'#7C2D12'],
  'bridge'     => ['bg'=>'#CFFAFE','border'=>'#06B6D4','text'=>'#164E63'],
  'implant'    => ['bg'=>'#FCE7F3','border'=>'#EC4899','text'=>'#831843'],
  'denture'    => ['bg'=>'#FEF3C7','border'=>'#D97706','text'=>'#78350F'],
];

$uid      = htmlspecialchars($chart_uid);
$is_input = ($tc_mode === 'input');

if (!function_exists('tc_quadrant_name')) {
    function tc_quadrant_name(string $t): string {
        $q = (int)$t[0];
        return [1=>'upper right',2=>'upper left',3=>'lower left',4=>'lower right',
                5=>'upper right',6=>'upper left',7=>'lower left',8=>'lower right'][$q] ?? '';
    }
}

/**
 * Print one tooth box.
 *   $kind  'note'  = empty writing box (holds the typed mark, or a check mark when tagged)
 *          'label' = box with the primary-tooth letter in it
 */
if (!function_exists('tc_box')) {
    function tc_box(string $tooth, string $kind, string $text, int $col, int $row,
                    array $chart_teeth, array $chart_marks, array $status_colors,
                    bool $is_input, string $uid, string $title): void {
        $style = "grid-column:{$col};grid-row:{$row};";
        $active = false;
        if (isset($chart_teeth[$tooth])) {
            $st = $chart_teeth[$tooth];
            if (isset($status_colors[$st])) {
                $c = $status_colors[$st];
                $style .= "background:{$c['bg']};border-color:{$c['border']};color:{$c['text']};";
                $active = true;
            }
        }
        $mark = isset($chart_marks[$tooth]) ? (string)$chart_marks[$tooth] : '';
        $cls = 'tc-box tc-' . $kind . ($is_input ? ' tc-btn' : '') . ($active ? ' tc-selected' : '');
        echo '<span class="' . $cls . '" data-tooth="' . htmlspecialchars($tooth) . '" data-kind="' . $kind . '"'
           . ' data-uid="' . $uid . '" title="' . htmlspecialchars($title) . '" style="' . $style . '">';
        if ($kind === 'label') {
            echo htmlspecialchars($text);
        } elseif ($is_input) {
            // typed mark lives in a tiny input; it is read-only until "Write marks" is switched on
            echo '<input type="text" class="tc-mark" maxlength="3" autocomplete="off" autocapitalize="characters"'
               . ' spellcheck="false" readonly tabindex="-1" aria-label="' . htmlspecialchars($title) . ' mark"'
               . ' value="' . htmlspecialchars($mark) . '" placeholder="' . ($active ? '✓' : '') . '">';
        } else {
            if ($mark !== '' && function_exists('normalize_mark_case')) $mark = normalize_mark_case($mark);
            echo htmlspecialchars($mark !== '' ? $mark : ($active ? '✓' : ''));
        }
        echo '</span>' . "\n";
    }
}
?>
<style>
/* ── Paper-style boxed tooth chart ─── */
.tc-paper-<?= $uid ?> { font-family: inherit; user-select: none; -webkit-user-select: none; max-width: 640px; margin: 0 auto; }
.tc-paper-<?= $uid ?> .tc-grid {
    --tc-line: #334155;
    display: grid;
    grid-template-columns: repeat(16, minmax(19px, 1fr));
    grid-template-rows: 24px 28px 24px 28px 24px 28px 24px;
    min-width: 308px;
    padding: 0 1px 1px 0;           /* room for the overlapped right/bottom borders */
    box-sizing: border-box;
}
.tc-paper-<?= $uid ?> .tc-box,
.tc-paper-<?= $uid ?> .tc-num {
    display: flex; align-items: center; justify-content: center;
    border: 1px solid var(--tc-line);
    margin: 0 -1px -1px 0;          /* neighbouring boxes share one line, like the paper */
    background: #FFFFFF;
    font-size: 0.74rem; font-weight: 700; color: #1E293B;
    box-sizing: border-box; line-height: 1; transition: background 0.12s, border-color 0.12s;
    min-width: 0; overflow: hidden;
}
.tc-paper-<?= $uid ?> .tc-num   { background: #F8FAFC; }
.tc-paper-<?= $uid ?> .tc-label { background: #F8FAFC; }
.tc-paper-<?= $uid ?> .tc-btn   { cursor: pointer; }
.tc-paper-<?= $uid ?> .tc-btn:hover { background: #E0F2FE; }
.tc-paper-<?= $uid ?> .tc-selected,
.tc-paper-<?= $uid ?> .tc-selected:hover { position: relative; z-index: 1; border-width: 1.5px; }

/* the little text field inside each empty box */
.tc-paper-<?= $uid ?> .tc-mark {
    width: 100%; height: 100%; min-width: 0; padding: 0; margin: 0;
    border: 0; outline: 0; background: transparent; box-shadow: none;
    text-align: center; font: inherit; font-size: 0.8rem; font-weight: 800;
    color: inherit; cursor: inherit;
    pointer-events: none;           /* tag mode: the click goes to the box */
    caret-color: #2563EB;
}
.tc-paper-<?= $uid ?> .tc-mark::placeholder { color: currentColor; opacity: 1; }
.tc-paper-<?= $uid ?>.tc-writing .tc-mark { pointer-events: auto; cursor: text; }
.tc-paper-<?= $uid ?>.tc-writing .tc-note { cursor: text; }
.tc-paper-<?= $uid ?>.tc-writing .tc-label.tc-btn { cursor: default; }
.tc-paper-<?= $uid ?>.tc-writing .tc-note:focus-within { background: #FEFCE8; box-shadow: inset 0 0 0 2px #2563EB; z-index: 2; position: relative; }
@media (pointer: coarse) { .tc-paper-<?= $uid ?> .tc-mark:focus { font-size: 16px; } }  /* stops iPhone zoom-in */

/* mode switch */
.tc-modebar-<?= $uid ?> { display:flex; gap:6px; justify-content:center; margin:0 0 8px; flex-wrap:wrap; }
.tc-modebar-<?= $uid ?> button {
    font-size: 0.74rem; font-weight: 700; padding: 5px 12px; border-radius: 8px; cursor: pointer;
    border: 1.5px solid #CBD5E1; background: #FFFFFF; color: #64748B; font-family: inherit;
}
.tc-modebar-<?= $uid ?> button.active { background:#DBEAFE; border-color:#3B82F6; color:#1E3A8A; }
.tc-hint-<?= $uid ?> { text-align:center; font-size:0.66rem; color:#94A3B8; margin-top:6px; }

.tc-legend { display:flex; flex-wrap:wrap; gap:6px; justify-content:center; margin-top:10px; font-size:0.67rem; }
.tc-legend-item { display:flex; align-items:center; gap:4px; color:#64748B; cursor:pointer; padding:2px 6px; border-radius:4px; border:1px solid transparent; }
.tc-legend-swatch { width:12px; height:12px; border-radius:2px; flex-shrink:0; }

[data-theme="dark"] .tc-paper-<?= $uid ?> .tc-grid { --tc-line: #64748B; }
[data-theme="dark"] .tc-paper-<?= $uid ?> .tc-box:not(.tc-selected) { background:#1E293B; color:#CBD5E1; }
[data-theme="dark"] .tc-paper-<?= $uid ?> .tc-num,
[data-theme="dark"] .tc-paper-<?= $uid ?> .tc-label:not(.tc-selected) { background:#334155; color:#E2E8F0; }
[data-theme="dark"] .tc-paper-<?= $uid ?> .tc-btn:not(.tc-selected):hover { background:#475569; }
[data-theme="dark"] .tc-paper-<?= $uid ?>.tc-writing .tc-note:focus-within { background:#422006; }
[data-theme="dark"] .tc-modebar-<?= $uid ?> button { background:#1E293B; border-color:#475569; color:#CBD5E1; }
[data-theme="dark"] .tc-modebar-<?= $uid ?> button.active { background:#1E3A8A; border-color:#3B82F6; color:#DBEAFE; }
[data-theme="dark"] .tc-hint-<?= $uid ?> { color:#64748B; }
@media print {
    .tc-paper-<?= $uid ?> .tc-grid { --tc-line: #000; }
    .tc-paper-<?= $uid ?> .tc-box, .tc-paper-<?= $uid ?> .tc-num { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<div class="tc-paper-<?= $uid ?>" id="tcRoot_<?= $uid ?>">
<?php if ($is_input): ?>
  <div class="tc-modebar-<?= $uid ?>">
    <button type="button" class="active" data-mode="tag">✓ Tag teeth</button>
    <button type="button" data-mode="write">✎ Write marks (0, X, F, Am…)</button>
  </div>
<?php endif; ?>

  <div class="tc-grid" role="group" aria-label="Tooth chart">
<?php
// ROW 1 + 2 — primary upper (columns 4-13): note box on top, letter box under it
foreach ($upper_prim as $i => $t) {
    $col = 4 + $i;
    $ttl = "Primary tooth {$primary_labels[$t]} (" . tc_quadrant_name($t) . ") — FDI $t";
    tc_box($t, 'note',  $primary_labels[$t], $col, 1, $chart_teeth, $chart_marks, $status_colors, $is_input, $uid, $ttl);
    tc_box($t, 'label', $primary_labels[$t], $col, 2, $chart_teeth, $chart_marks, $status_colors, $is_input, $uid, $ttl);
}
// ROW 3 — upper permanent note boxes (16)
foreach ($upper_perm as $i => $t) {
    $n = ($i < 8) ? 8 - $i : $i - 7;
    $ttl = "Upper " . (($i < 8) ? 'right' : 'left') . " $n — FDI $t";
    tc_box((string)$t, 'note', (string)$n, $i + 1, 3, $chart_teeth, $chart_marks, $status_colors, $is_input, $uid, $ttl);
}
// ROW 4 — shared number row 8 7 6 5 4 3 2 1 | 1 2 3 4 5 6 7 8 (label only)
for ($i = 0; $i < 16; $i++) {
    $n = ($i < 8) ? 8 - $i : $i - 7;
    echo '<span class="tc-num" style="grid-column:' . ($i + 1) . ';grid-row:4;">' . $n . '</span>' . "\n";
}
// ROW 5 — lower permanent note boxes (16)
foreach ($lower_perm as $i => $t) {
    $n = ($i < 8) ? 8 - $i : $i - 7;
    $ttl = "Lower " . (($i < 8) ? 'right' : 'left') . " $n — FDI $t";
    tc_box((string)$t, 'note', (string)$n, $i + 1, 5, $chart_teeth, $chart_marks, $status_colors, $is_input, $uid, $ttl);
}
// ROW 6 + 7 — primary lower (columns 4-13): letter box, then note box under it
foreach ($lower_prim as $i => $t) {
    $col = 4 + $i;
    $ttl = "Primary tooth {$primary_labels[$t]} (" . tc_quadrant_name($t) . ") — FDI $t";
    tc_box($t, 'label', $primary_labels[$t], $col, 6, $chart_teeth, $chart_marks, $status_colors, $is_input, $uid, $ttl);
    tc_box($t, 'note',  $primary_labels[$t], $col, 7, $chart_teeth, $chart_marks, $status_colors, $is_input, $uid, $ttl);
}
?>
  </div>

<?php if ($is_input): ?>
  <input type="hidden" name="<?= htmlspecialchars($tc_marks_name) ?>" id="tcMarks_<?= $uid ?>"
         value="<?= htmlspecialchars($chart_marks ? json_encode($chart_marks) : '') ?>">
  <div class="tc-hint-<?= $uid ?>" id="tcHint_<?= $uid ?>">Tap the box above the number for the upper tooth, below the number for the lower tooth. A tagged tooth prints as ✓ (sound tooth).</div>
<?php endif; ?>

<?php if ($is_input): ?>
  <!-- The doctor's chart legend (same codes as the printed record) -->
  <div id="tcDocLegend_<?= $uid ?>" style="margin-top:10px;padding:7px 10px;border:1px solid var(--gray-200,#e2e8f0);border-radius:8px;background:var(--white,#fff);font-size:0.68rem;line-height:1.7;color:var(--gray-600,#475569);text-align:center;">
    <b>Legends:</b>
    <span style="white-space:nowrap;"><b>&#10003;</b> Sound tooth</span> &middot;
    <span style="white-space:nowrap;"><b>0</b> Missing / extracted tooth</span> &middot;
    <span style="white-space:nowrap;"><b>X</b> Indicated for extraction</span> &middot;
    <span style="white-space:nowrap;"><b>F</b> Filled</span> &middot;
    <span style="white-space:nowrap;"><b>Am</b> Amalgam filling</span> &middot;
    <span style="white-space:nowrap;"><b>Co</b> Composite filling</span> &middot;
    <span style="white-space:nowrap;"><b>Ec</b> Extensive caries</span> &middot;
    <span style="white-space:nowrap;"><b>Im</b> Impacted tooth</span> &middot;
    <span style="white-space:nowrap;"><b>Pe</b> Partially erupted tooth</span> &middot;
    <span style="white-space:nowrap;"><b>Jc</b> Jacket crown</span> &middot;
    <span style="white-space:nowrap;"><b>Br</b> Bridge</span>
  </div>
<?php endif; ?>

<?php if ($is_input): ?>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;flex-wrap:wrap;gap:6px;">
    <span id="tcDisplay_<?= $uid ?>" style="font-size:0.78rem;color:var(--gray-500);">No teeth selected — click to tag</span>
    <button type="button" onclick="tcClear('<?= $uid ?>')"
            style="font-size:0.72rem;padding:3px 10px;background:none;border:1px solid var(--gray-300);border-radius:6px;cursor:pointer;color:var(--gray-500);">
        ✕ Clear chart
    </button>
  </div>
<?php endif; ?>
</div><!-- /tc-paper -->

<?php if ($is_input): ?>
<script>
(function(){
    var uid      = <?= json_encode($uid) ?>;
    var inputSel = <?= json_encode('[name="' . $tc_input_name . '"]') ?>;
    var selId    = <?= json_encode($ts_select_id) ?>;
    var initial  = <?= json_encode($tc_initial) ?>;
    var root     = document.getElementById('tcRoot_' + uid);
    var marksEl  = document.getElementById('tcMarks_' + uid);
    var hintEl   = document.getElementById('tcHint_' + uid);

    var selected = new Set();
    var STATUS_COLORS = <?= json_encode($status_colors) ?>;
    var writing = false;

    var HINT_TAG   = 'Tap the box above the number for the upper tooth, below the number for the lower tooth. A tagged tooth prints as ✓ (sound tooth).';
    var HINT_WRITE = 'Tap any empty box and type the code from the Legends below: 0 = missing/extracted, X = for extraction, F = filled, Am, Co, Ec, Im, Pe, Jc, Br. Leave it blank to erase.';

    function getStatus() {
        var s = document.getElementById(selId);
        return s ? s.value : 'normal';
    }
    function sortTeeth(arr) {
        return arr.sort(function(a,b){ return parseInt(a)-parseInt(b)||a.localeCompare(b); });
    }
    var MARK_CASE = {AM:'Am', CO:'Co', EC:'Ec', IM:'Im', PE:'Pe', JC:'Jc', BR:'Br'};   // doctor's codes keep their case
    function cleanMark(v) {
        var u = (v || '').toUpperCase().replace(/[^A-Z0-9+\-\/.]/g, '').slice(0, 3);
        return MARK_CASE[u] || u;
    }

    function repaintAll() {
        var st = getStatus();
        var c = STATUS_COLORS[st] || {bg:'#DBEAFE', border:'#3B82F6', text:'#1E3A8A'};
        root.querySelectorAll('.tc-btn').forEach(function(el){
            var on = selected.has(el.dataset.tooth);
            el.classList.toggle('tc-selected', on);
            // Colours are set one by one (not via cssText) so the grid placement stays intact
            if (on) {
                el.style.background  = c.bg;
                el.style.borderColor = c.border;
                el.style.color       = c.text;
            } else {
                el.style.removeProperty('background');
                el.style.removeProperty('border-color');
                el.style.removeProperty('color');
            }
            var mk = el.querySelector('.tc-mark');
            if (mk) mk.placeholder = on ? '✓' : '';
        });
        updateDisplay();
        updateLegend();
    }

    function updateDisplay() {
        var el = document.getElementById('tcDisplay_'+uid);
        if (!el) return;
        if (selected.size === 0) {
            el.textContent = 'No teeth selected — click to tag';
        } else {
            var nums = sortTeeth(Array.from(selected));
            el.innerHTML = '<strong style="color:var(--blue-600);">'+(selected.size > 1 ? selected.size+' teeth' : '1 tooth')+':</strong> '+
                nums.map(function(t){ return '<span style="display:inline-block;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:3px;padding:1px 4px;font-size:0.72rem;margin:1px;">'+t+'</span>'; }).join(' ');
        }
    }

    function syncInput() {
        var nums = sortTeeth(Array.from(selected));
        var inp = document.querySelector(inputSel);
        if (inp) inp.value = nums.join(', ');
        updateDisplay();
    }

    function syncMarks() {
        var out = {};
        root.querySelectorAll('.tc-note .tc-mark').forEach(function(m){
            var v = cleanMark(m.value);
            if (v) out[m.parentNode.dataset.tooth] = v;
        });
        if (marksEl) marksEl.value = Object.keys(out).length ? JSON.stringify(out) : '';
    }

    function updateLegend() {
        var st = getStatus();
        var legend = document.getElementById('tcLegend_'+uid);
        if (!legend) return;
        legend.querySelectorAll('.tc-legend-item').forEach(function(el){
            var s = el.dataset.status, c = STATUS_COLORS[s];
            if (s === st) { el.style.borderColor = c ? c.border : '#3B82F6'; el.style.background = c ? c.bg+'44' : ''; }
            else          { el.style.borderColor = 'transparent'; el.style.background = ''; }
        });
    }

    function setMode(w) {
        writing = w;
        root.classList.toggle('tc-writing', w);
        root.querySelectorAll('.tc-mark').forEach(function(m){
            if (w) { m.removeAttribute('readonly'); m.removeAttribute('tabindex'); }
            else   { m.setAttribute('readonly', ''); m.setAttribute('tabindex', '-1'); m.blur(); }
        });
        root.querySelectorAll('[class*="tc-modebar"] button').forEach(function(b){
            b.classList.toggle('active', (b.dataset.mode === 'write') === w);
        });
        if (hintEl) hintEl.textContent = w ? HINT_WRITE : HINT_TAG;
    }

    // mode switch buttons
    root.querySelectorAll('[class*="tc-modebar"] button').forEach(function(b){
        b.addEventListener('click', function(){ setMode(this.dataset.mode === 'write'); });
    });

    // tagging (only in "Tag teeth" mode)
    root.querySelectorAll('.tc-btn').forEach(function(el){
        el.addEventListener('click', function(){
            if (writing) {                       // write mode: just focus the box's text field
                var mk = this.querySelector('.tc-mark');
                if (mk) mk.focus();
                return;
            }
            var t = this.dataset.tooth;
            if (selected.has(t)) selected.delete(t); else selected.add(t);
            repaintAll();
            syncInput();
        });
    });

    // typing marks
    root.querySelectorAll('.tc-mark').forEach(function(m){
        m.addEventListener('input', function(){
            var v = cleanMark(this.value);
            if (this.value !== v) this.value = v;
            syncMarks();
        });
        m.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); this.blur(); } });
    });

    var statusSelect = document.getElementById(selId);
    if (statusSelect) statusSelect.addEventListener('change', function(){ repaintAll(); });

    var legend = document.getElementById('tcLegend_'+uid);
    if (legend) {
        legend.querySelectorAll('.tc-legend-item').forEach(function(el){
            el.addEventListener('click', function(){
                var sel = document.getElementById(selId);
                if (sel) { sel.value = this.dataset.status; sel.dispatchEvent(new Event('change')); }
            });
        });
    }

    var inp = document.querySelector(inputSel);
    if (inp) {
        inp.addEventListener('input', function(){
            var parts = this.value.split(/[\s,;]+/).map(function(s){ return s.trim(); }).filter(Boolean);
            selected = new Set(parts);
            repaintAll();
        });
    }

    if (initial) {
        initial.split(/[\s,;]+/).forEach(function(t){ if(t.trim()) selected.add(t.trim()); });
        repaintAll();
        syncInput();
    }
    updateLegend();

    window.tcClear = function(u) {
        if (u !== uid) return;
        selected.clear();
        root.querySelectorAll('.tc-mark').forEach(function(m){ m.value = ''; });
        var inp2 = document.querySelector(inputSel);
        if (inp2) inp2.value = '';
        syncMarks();
        repaintAll();
    };
})();
</script>
<?php endif; ?>
