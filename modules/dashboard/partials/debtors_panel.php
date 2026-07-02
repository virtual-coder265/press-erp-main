<?php
/**
 * Dashboard partial - debtors follow-up panel.
 *
 * Component id: dashboard.debtors.panel
 * Required context:
 *   - $dashboardCanViewDebtorsPanel
 *   - $dashboardDebtors, $dashboardDebtorsTotalBalance
 *   - $dashboardDebtorsCriticalCount, $dashboardDebtorsReminderAt
 */
?>
<div class="dashboard-debtors-panel-shell"
     data-ajax-component="dashboard.debtors.panel"
     data-ajax-poll="120000"
     data-ajax-refresh-on="focus"
     data-ajax-stale="60000">
<?php if ($dashboardCanViewDebtorsPanel): ?>
    <div class="dashboard-debtors-card" aria-label="Quick balance debtors">
        <div class="dashboard-debtors-head">
            <div>
                <span class="dashboard-debtors-kicker">Quick balance</span>
                <strong class="dashboard-debtors-title">Debtors follow-up</strong>
            </div>
            <div class="dashboard-debtors-total">
                MK <?php echo dashboardCurrency($dashboardDebtorsTotalBalance); ?>
                <span>Top balance</span>
            </div>
        </div>

        <div class="dashboard-debtors-summary" aria-label="Debtor summary">
            <div class="dashboard-debtors-chip">
                <span>Debtors</span>
                <strong><?php echo number_format(count($dashboardDebtors)); ?></strong>
            </div>
            <div class="dashboard-debtors-chip">
                <span>60+ days</span>
                <strong><?php echo number_format($dashboardDebtorsCriticalCount); ?></strong>
            </div>
        </div>

        <?php if (!empty($dashboardDebtors)): ?>
        <div class="dashboard-debtors-table-wrap">
            <table class="dashboard-debtors-table">
                <thead>
                    <tr>
                        <th scope="col" class="text-left">Debtor</th>
                        <th scope="col" class="text-right">Balance</th>
                        <th scope="col" class="text-center">Age</th>
                        <th scope="col" class="text-right">Act</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dashboardDebtors as $debtor): ?>
                        <?php
                        $debtorName = (string) ($debtor['debtor_name'] ?? 'Unknown debtor');
                        $debtorBalance = (float) ($debtor['balance'] ?? 0);
                        $debtorInvoiceCount = (int) ($debtor['invoice_count'] ?? 0);
                        $ageMeta = dashboardDebtAgeMeta((int) ($debtor['max_age_days'] ?? 0));
                        $invoiceLookupUrl = BASE_URL . 'modules/invoices/list?' . http_build_query(['search' => $debtorName]);
                        $latestInvoiceUrl = !empty($debtor['latest_invoice_id'])
                            ? BASE_URL . 'modules/invoices/view?id=' . (int) $debtor['latest_invoice_id']
                            : $invoiceLookupUrl;
                        $reminderTitle = 'Follow up debtor: ' . $debtorName;
                        $reminderNote = 'Outstanding balance MK ' . dashboardCurrency($debtorBalance)
                            . ' across ' . $debtorInvoiceCount . ' invoice' . ($debtorInvoiceCount === 1 ? '' : 's')
                            . '. Debt age: ' . $ageMeta['label'] . '.';
                        ?>
                        <tr>
                            <td>
                                <span class="dashboard-debtor-name"><?php echo htmlspecialchars($debtorName); ?></span>
                                <span class="dashboard-debtor-meta"><?php echo number_format($debtorInvoiceCount); ?> invoice<?php echo $debtorInvoiceCount === 1 ? '' : 's'; ?></span>
                            </td>
                            <td class="text-right">
                                <span class="dashboard-debtor-balance">MK <?php echo dashboardCurrency($debtorBalance); ?></span>
                            </td>
                            <td class="text-center">
                                <span class="dashboard-debtor-age" data-tone="<?php echo htmlspecialchars($ageMeta['tone']); ?>">
                                    <?php echo htmlspecialchars($ageMeta['label']); ?>
                                </span>
                            </td>
                            <td class="text-right">
                                <span class="dashboard-debtor-actions">
                                    <a href="<?php echo htmlspecialchars($latestInvoiceUrl); ?>" class="dashboard-debtor-action" title="Open latest invoice">
                                        <i data-lucide="eye" aria-hidden="true"></i>
                                    </a>
                                    <a href="<?php echo htmlspecialchars($invoiceLookupUrl); ?>"
                                       class="dashboard-debtor-action"
                                       title="Create follow-up reminder"
                                       data-action-modal="reminder.create"
                                       data-action-option-title="<?php echo htmlspecialchars($reminderTitle); ?>"
                                       data-action-option-remind-at="<?php echo htmlspecialchars($dashboardDebtorsReminderAt); ?>"
                                       data-action-option-note="<?php echo htmlspecialchars($reminderNote); ?>">
                                        <i data-lucide="bell-dot" aria-hidden="true"></i>
                                    </a>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="dashboard-debtors-empty">
                No debtor balances need follow-up right now.
            </div>
        <?php endif; ?>

        <div class="dashboard-debtors-footer">
            <span>Age is based on due date.</span>
            <a href="<?php echo BASE_URL; ?>modules/invoices/list?status=Overdue">
                Debt age lookup
                <i data-lucide="arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
<?php endif; ?>
</div>
