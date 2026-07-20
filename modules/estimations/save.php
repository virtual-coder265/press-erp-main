<?php
require_once __DIR__ . '/../../config/app.php';
checkAuth();
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions_helper.php';
permissions_require_one_of(['manage_estimations']);
require_once __DIR__ . '/../../libs/EstimationAuditMigrator.php';

EstimationAuditMigrator::ensure($pdo);

/**
 * Parse a formatted currency value (e.g., "MK2,498.34") to float
 * Handles both formatted (MK2,498.34) and raw (2498.34) values
 */
function parseCurrency($value) {
    if (empty($value)) return 0;
    // Remove 'MK' prefix and all commas
    $cleaned = str_replace(['MK', ','], '', (string)$value);
    return floatval($cleaned);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $pdo->beginTransaction();

        // Resolve whether we are finalising an existing autosaved/manual draft.
        $est_id = isset($_POST['est_id']) && $_POST['est_id'] !== '' ? (int) $_POST['est_id'] : null;
        $existingEst = null;
        $is_existing_draft = false;

        if ($est_id) {
            $checkStmt = $pdo->prepare("SELECT id, status, estimation_number FROM estimations WHERE id = :id AND created_by = :user");
            $checkStmt->execute(['id' => $est_id, 'user' => $_SESSION['user_id']]);
            $existingEst = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingEst && $existingEst['status'] === 'Draft') {
                $is_existing_draft = true;
            } elseif ($existingEst) {
                http_response_code(403);
                $pdo->rollBack();
                die('Cannot update this estimation.');
            } else {
                // Stale/invalid est_id from the client — start fresh.
                $est_id = null;
            }
        }

        $est_number = null;
        if (!$is_existing_draft) {
            $est_number = 'EST-' . date('Y') . '-' . mt_rand(1000, 9999);
        } else {
            $currentNumber = (string) ($existingEst['estimation_number'] ?? '');
            if ($currentNumber === '' || str_starts_with($currentNumber, 'DRAFT-')) {
                $est_number = 'EST-' . date('Y') . '-' . mt_rand(1000, 9999);
            }
        }

        // --- Re-derive the breakdown server-side -------------------------------
        // We never trust the wizard's hidden totals blindly; we recompute the
        // cost subtotal from the same fields the JS uses, then store every
        // intermediate value so an invoice generated later can pull pre-VAT
        // totals + VAT % directly.
        $costSubtotal       = parseCurrency($_POST['subtotal'] ?? 0);
        $costConsumables    = (float) ($_POST['cost_consumables'] ?? 0);
        $costSupervision    = (float) ($_POST['cost_labour']      ?? 0);
        $profitPercent      = (float) ($_POST['profit_margin']    ?? 0);
        $vatPercent         = (float) ($_POST['vat_percent']      ?? 0);

        // baseCost mirrors the JS: `subtotal + extraLabour` (extraLabour is the
        // overtime/supervision number on the totals step).
        $baseCost           = $costSubtotal + $costSupervision;
        $profitAmount       = round($baseCost * ($profitPercent / 100), 2);
        $taxableAmount      = round($baseCost + $profitAmount, 2);
        $vatAmount          = round($taxableAmount * ($vatPercent / 100), 2);
        $grandTotalDerived  = round($taxableAmount + $vatAmount, 2);
        $grandTotal         = parseCurrency($_POST['grand_total'] ?? 0);
        if ($grandTotal <= 0) {
            $grandTotal = $grandTotalDerived;
        }

        // Main Estimation. last_edited_* mirrors created_* on first save so
        // the audit notice on the view page is meaningful from day one.
        $jobText = ($_POST['job_title'] ?? '') . ' (' . ($_POST['job_type'] ?? 'General') . ')';
        if (!empty($_POST['job_description'])) {
            $jobText .= "\n" . $_POST['job_description'];
        }

        if ($is_existing_draft) {
            // Finalise an existing draft (from edit_draft or autosaved new estimation).
            $numberSql = $est_number !== null ? 'estimation_number = :num,' : '';
            $stmt = $pdo->prepare("UPDATE estimations SET
                {$numberSql}
                customer_name = :name,
                customer_email = :email,
                customer_phone = :phone,
                job_description = :job,
                total_amount = :total,
                last_edited_at = NOW(),
                last_edited_by = :editor,
                subtotal_amount = :subtotal,
                profit_margin_percent = :profit_pct,
                profit_amount = :profit_amt,
                cost_supervision_amount = :supervision,
                cost_consumables_amount = :consumables,
                vat_percent = :vat_pct,
                vat_amount = :vat_amt,
                pre_vat_total = :pre_vat,
                draft_data = NULL,
                draft_step = 8,
                draft_revision = 0,
                draft_content_hash = NULL
                WHERE id = :id");

            $updateParams = [
                'id'          => $est_id,
                'name'        => $_POST['customer_name']  ?? 'Unknown',
                'email'       => $_POST['customer_email'] ?? '',
                'phone'       => $_POST['customer_phone'] ?? '',
                'job'         => $jobText,
                'total'       => $grandTotal,
                'editor'      => $_SESSION['user_id'],
                'subtotal'    => $costSubtotal,
                'profit_pct'  => $profitPercent,
                'profit_amt'  => $profitAmount,
                'supervision' => $costSupervision,
                'consumables' => $costConsumables,
                'vat_pct'     => $vatPercent,
                'vat_amt'     => $vatAmount,
                'pre_vat'     => $taxableAmount,
            ];
            if ($est_number !== null) {
                $updateParams['num'] = $est_number;
            }

            $stmt->execute($updateParams);
            
            // Delete existing items before re-adding
            $delStmt = $pdo->prepare("DELETE FROM estimation_items WHERE estimation_id = :id");
            $delStmt->execute(['id' => $est_id]);
            
        } else {
            // Create new estimation
            $stmt = $pdo->prepare("INSERT INTO estimations
                (estimation_number, customer_name, customer_email, customer_phone, job_description, total_amount, created_by, status,
                 last_edited_at, last_edited_by,
                 subtotal_amount, profit_margin_percent, profit_amount,
                 cost_supervision_amount, cost_consumables_amount,
                 vat_percent, vat_amount, pre_vat_total)
                VALUES (:num, :name, :email, :phone, :job, :total, :user, 'Draft',
                        NOW(), :editor,
                        :subtotal, :profit_pct, :profit_amt,
                        :supervision, :consumables,
                        :vat_pct, :vat_amt, :pre_vat)");

            $stmt->execute([
                'num'         => $est_number,
                'name'        => $_POST['customer_name']  ?? 'Unknown',
                'email'       => $_POST['customer_email'] ?? '',
                'phone'       => $_POST['customer_phone'] ?? '',
                'job'         => $jobText,
                'total'       => $grandTotal,
                'user'        => $_SESSION['user_id'],
                'editor'      => $_SESSION['user_id'],
                'subtotal'    => $costSubtotal,
                'profit_pct'  => $profitPercent,
                'profit_amt'  => $profitAmount,
                'supervision' => $costSupervision,
                'consumables' => $costConsumables,
                'vat_pct'     => $vatPercent,
                'vat_amt'     => $vatAmount,
                'pre_vat'     => $taxableAmount,
            ]);

            $est_id = $pdo->lastInsertId();
        }

        $stmtItem = $pdo->prepare("INSERT INTO estimation_items
            (estimation_id, item_type, description, quantity, unit_price, total_price, details_json)
            VALUES (:eid, :type, :desc, :qty, :price, :total, :json)");

        // 1. Standard & Dynamic Materials
        if (!empty($_POST['material_id'])) {
            foreach ($_POST['material_id'] as $index => $mat_id) {
                if (empty($mat_id))
                    continue;
                $qty = floatval($_POST['material_qty'][$index] ?? 0);
                $rate = floatval($_POST['material_rate'][$index] ?? 0);
                $total = parseCurrency($_POST['material_total'][$index] ?? 0);

                $mStmt = $pdo->prepare("SELECT name FROM materials WHERE id = ?");
                $mStmt->execute([$mat_id]);
                $mat_name = $mStmt->fetchColumn();

                $rStmt = $pdo->prepare("SELECT rate FROM material_rates WHERE material_id = ? ORDER BY effective_date DESC LIMIT 1");
                $rStmt->execute([$mat_id]);
                $old_rate = $rStmt->fetchColumn();
                if ($old_rate !== null && (float) $old_rate !== $rate) {
                    $uStmt = $pdo->prepare("INSERT INTO material_rates (material_id, rate, effective_date, created_by) VALUES (?, ?, CURDATE(), ?)");
                    $uStmt->execute([$mat_id, $rate, $_SESSION['user_id']]);
                }

                $stmtItem->execute([
                    'eid' => $est_id,
                    'type' => 'Material',
                    'desc' => $mat_name,
                    'qty' => $qty,
                    'price' => $rate,
                    'total' => $total,
                    'json' => json_encode(['material_id' => $mat_id])
                ]);
            }
        }

        // 2. Multi-Paper Entries
        if (!empty($_POST['paper_sheets'])) {
            $paperStmt = $pdo->prepare("INSERT INTO estimation_papers
                (estimation_id, paper_type, paper_size, paper_grammage, paper_color, paper_sheets, paper_rate, paper_total, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($_POST['paper_sheets'] as $i => $sheets) {
                $sheets = floatval($sheets);
                $rate = floatval($_POST['paper_rate'][$i] ?? 0);
                $total = parseCurrency($_POST['paper_total'][$i] ?? 0);
                $paperStmt->execute([
                    $est_id,
                    $_POST['paper_type'][$i] ?? '',
                    $_POST['paper_size'][$i] ?? '',
                    floatval($_POST['paper_grammage'][$i] ?? 0),
                    $_POST['paper_color'][$i] ?? '',
                    $sheets,
                    $rate,
                    $total,
                    $i
                ]);
            }
            // Save paper total as estimation item
            $paperTotal = parseCurrency($_POST['cost_paper'] ?? 0);
            if ($paperTotal > 0) {
                $stmtItem->execute([
                    'eid' => $est_id,
                    'type' => 'Material',
                    'desc' => 'Paper Stock',
                    'qty' => 1,
                    'price' => $paperTotal,
                    'total' => $paperTotal,
                    'json' => json_encode(['multi_paper' => true])
                ]);
            }
        }

        // 3. Ink (formula only / formula+breakdown / breakdown only)
        $inkCalcMode = strtolower(trim((string) ($_POST['ink_calc_mode'] ?? 'formula_breakdown')));
        if (!in_array($inkCalcMode, ['formula', 'formula_breakdown', 'breakdown'], true)) {
            $inkCalcMode = 'formula_breakdown';
        }
        $inkTotal = parseCurrency($_POST['cost_ink'] ?? 0);

        if (!empty($_POST['ink_colour']) && $inkCalcMode !== 'formula') {
            $inkStmt = $pdo->prepare("INSERT INTO estimation_ink_colours
                (estimation_id, colour_name, kgs, rate, total, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($_POST['ink_colour'] as $i => $colour) {
                $kgs = floatval($_POST['ink_colour_kgs'][$i] ?? 0);
                $rate = floatval($_POST['ink_colour_rate'][$i] ?? 0);
                $total = parseCurrency($_POST['ink_colour_total'][$i] ?? 0);
                $pct = floatval($_POST['ink_colour_pct'][$i] ?? 0);
                if ($kgs > 0 || $pct > 0 || !empty($colour)) {
                    $inkStmt->execute([$est_id, $colour, $kgs, $rate, $total, $i]);
                }
            }
        }

        if ($inkTotal > 0) {
            $stmtItem->execute([
                'eid' => $est_id,
                'type' => 'Material',
                'desc' => 'Ink',
                'qty' => 1,
                'price' => $inkTotal,
                'total' => $inkTotal,
                'json' => json_encode([
                    'mode' => $inkCalcMode,
                    'base' => $_POST['ink_measure_base'] ?? 0,
                    'height' => $_POST['ink_height'] ?? 0,
                    'pages' => $_POST['ink_pages'] ?? 0,
                    'copies' => $_POST['ink_quantity_copies'] ?? 0,
                    'kgs' => $_POST['ink_kgs'] ?? 0,
                    'overall_rate' => $_POST['ink_overall_rate'] ?? null,
                    'percentages' => $_POST['ink_colour_pct'] ?? [],
                ]),
            ]);
        }

        // 4. Binding Materials
        if (!empty($_POST['binding_mat_qty'])) {
            $bindStmt = $pdo->prepare("INSERT INTO estimation_binding_materials
                (estimation_id, material_id, material_name, unit, quantity, rate, total, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($_POST['binding_mat_qty'] as $i => $qty) {
                $qty = floatval($qty);
                $rate = floatval($_POST['binding_mat_rate'][$i] ?? 0);
                $tot = parseCurrency($_POST['binding_mat_total'][$i] ?? 0);
                $mid = intval($_POST['binding_mat_id'][$i] ?? 0) ?: null;
                $name = $_POST['binding_mat_name'][$i] ?? '';
                if (empty($name) && $mid) {
                    $n = $pdo->prepare("SELECT name FROM materials WHERE id=?");
                    $n->execute([$mid]);
                    $name = $n->fetchColumn();
                }
                if ($qty > 0 || !empty($name)) {
                    $bindStmt->execute([$est_id, $mid, $name, $_POST['binding_mat_unit'][$i] ?? '', $qty, $rate, $tot, $i]);
                }
            }
            $bindTotal = parseCurrency($_POST['cost_binding'] ?? 0);
            if ($bindTotal > 0) {
                $stmtItem->execute([
                    'eid' => $est_id,
                    'type' => 'Material',
                    'desc' => 'Binding Materials',
                    'qty' => 1,
                    'price' => $bindTotal,
                    'total' => $bindTotal,
                    'json' => json_encode(['binding' => true])
                ]);
            }
        }

        // 5. Pre-press Labour
        if (!empty($_POST['prepress_name'])) {
            $ppStmt = $pdo->prepare("INSERT INTO estimation_prepress_labour
                (estimation_id, labour_name, unit, hrs, rate, total, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($_POST['prepress_name'] as $i => $name) {
                $hrs = floatval($_POST['prepress_hrs'][$i] ?? 0);
                $rate = floatval($_POST['prepress_rate'][$i] ?? 0);
                $tot = parseCurrency($_POST['prepress_total'][$i] ?? 0);
                if ($hrs > 0 || $rate > 0) {
                    $ppStmt->execute([$est_id, $name, 'hrs', $hrs, $rate, $tot, $i]);
                }
            }
            $ppTotal = parseCurrency($_POST['cost_prepress'] ?? 0);
            if ($ppTotal > 0) {
                $stmtItem->execute([
                    'eid' => $est_id,
                    'type' => 'Labor',
                    'desc' => 'Pre-press Labour',
                    'qty' => 1,
                    'price' => $ppTotal,
                    'total' => $ppTotal,
                    'json' => null
                ]);
            }
        }

        // 6. Press Labour
        if (!empty($_POST['press_machine_name'])) {
            $pressStmt = $pdo->prepare("INSERT INTO estimation_press_labour
                (estimation_id, machine_name, colours, make_ready_hrs, make_ready_rate, make_ready_total,
                 impressions, iph, running_hrs, running_rate, running_total, machine_total, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($_POST['press_machine_name'] as $i => $mname) {
                $mrHrs = floatval($_POST['press_mr_hrs'][$i] ?? 0);
                $mrRate = floatval($_POST['press_mr_rate'][$i] ?? 0);
                $mrTot = parseCurrency($_POST['press_mr_total'][$i] ?? 0);
                $runHrs = floatval($_POST['press_run_hrs'][$i] ?? 0);
                $runRate = floatval($_POST['press_run_rate'][$i] ?? 0);
                $runTot = parseCurrency($_POST['press_run_total'][$i] ?? 0);
                $mTot = $mrTot + $runTot;
                if (!empty($mname) || $mTot > 0) {
                    $pressStmt->execute([
                        $est_id,
                        $mname,
                        intval($_POST['press_colours'][$i] ?? 0),
                        $mrHrs,
                        $mrRate,
                        $mrTot,
                        intval($_POST['press_impressions'][$i] ?? 0),
                        intval($_POST['press_iph'][$i] ?? 0),
                        $runHrs,
                        $runRate,
                        $runTot,
                        $mTot,
                        $i
                    ]);
                }
            }
            $pressTotal = parseCurrency($_POST['cost_press'] ?? 0);
            if ($pressTotal > 0) {
                $stmtItem->execute([
                    'eid' => $est_id,
                    'type' => 'Labor',
                    'desc' => 'Press Labour',
                    'qty' => 1,
                    'price' => $pressTotal,
                    'total' => $pressTotal,
                    'json' => null
                ]);
            }
        }

        // 7. Finishing Labour
        if (!empty($_POST['finishing_name'])) {
            $finStmt = $pdo->prepare("INSERT INTO estimation_finishing_labour
                (estimation_id, labour_name, measure_type, impressions, iph, hrs, quantity, rate, total, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($_POST['finishing_name'] as $i => $fname) {
                $impressions = intval($_POST['finishing_impressions'][$i] ?? 0);
                $iph = intval($_POST['finishing_iph'][$i] ?? 0);
                $hrs = floatval($_POST['finishing_hrs'][$i] ?? 0);
                $qty = $hrs;
                $rate = floatval($_POST['finishing_rate'][$i] ?? 0);
                $tot = parseCurrency($_POST['finishing_total'][$i] ?? 0);
                if ($qty > 0 || $rate > 0 || $impressions > 0) {
                    $finStmt->execute([
                        $est_id,
                        $fname,
                        $_POST['finishing_measure'][$i] ?? 'items',
                        $impressions,
                        $iph,
                        $hrs,
                        $qty,
                        $rate,
                        $tot,
                        $i
                    ]);
                }
            }
            $finTotal = parseCurrency($_POST['cost_finishing'] ?? 0);
            if ($finTotal > 0) {
                $stmtItem->execute([
                    'eid' => $est_id,
                    'type' => 'Labor',
                    'desc' => 'Finishing Labour',
                    'qty' => 1,
                    'price' => $finTotal,
                    'total' => $finTotal,
                    'json' => null
                ]);
            }
        }

        // 8. Consumables & Overhead
        $staticItems = [
            'cost_consumables' => ['Consumables', 'Material'],
            'cost_labour' => ['Overtime & Supervision', 'Labor'],
        ];
        foreach ($staticItems as $field => [$desc, $type]) {
            $price = floatval($_POST[$field] ?? 0);
            if ($price > 0) {
                $stmtItem->execute([
                    'eid' => $est_id,
                    'type' => $type,
                    'desc' => $desc,
                    'qty' => 1,
                    'price' => $price,
                    'total' => $price,
                    'json' => null
                ]);
            }
        }

        $pdo->commit();
        $userId = (int) $_SESSION['user_id'];
        $savedEstId = (int) $est_id;
        echo "<script>
            (function () {
                var userId = " . $userId . ";
                var estId = " . $savedEstId . ";
                var keys = [
                    'estimation_draft:' + userId + ':active',
                    'estimation_draft:' + userId
                ];
                if (estId) {
                    keys.push('estimation_draft:' + userId + ':' + estId);
                }
                var tasks = [];
                if (window.FormDraftStore) {
                    keys.forEach(function (key) {
                        tasks.push(FormDraftStore.remove(key).catch(function () {}));
                    });
                    FormDraftStore.clearPointer();
                }
                try { localStorage.removeItem('estimation_draft_v4'); } catch (e) {}
                Promise.all(tasks).finally(function () {
                    window.location.href = 'list?success=created';
                });
            })();
        </script>";

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die("Error saving estimation: " . $e->getMessage());
    }
}
?>
