# Payments

Recording and validating payments against client invoices, and the invoice status transitions they drive. Part of the [Billing & Invoicing System](billing.md). See also [Overpayment credits](overpayment-credits.md) (overpaid amounts carry forward) and [Stripe billing](stripe-billing.md) (online payments for issued invoices).

## Payment Methods
Supported payment methods:
- Credit Card
- ACH
- Wire
- Check
- Other

## Payment Validation
The system enforces strict payment validation to maintain data integrity:

1. **Overpayment Prevention**:
   - When adding a new payment, the amount cannot exceed the invoice's remaining balance
   - When updating an existing payment, the total payments cannot exceed the invoice total
   - Both endpoints return HTTP 422 with a descriptive error message if validation fails

2. **Payment Amount Rules**:
   - Minimum payment amount: $0.01
   - Payment amounts must be numeric
   - Payment date is required and must be a valid date

## Invoice Status Transitions

The invoice status automatically updates based on payment activity:

1. **Draft → Issued**: Manual action by admin (sets `issue_date`)
2. **Issued → Paid**: Automatically triggered when `total_payments >= invoice_total`
   - `paid_date` is set to the latest payment date
   - Status changes from "issued" to "paid"
3. **Paid → Issued**: Automatically triggered when a payment is deleted or updated, causing `remaining_balance > 0`
   - `paid_date` is cleared
   - Status reverts to "issued"

## Partially Paid Status

While the database status remains "issued", the UI displays a special "PARTIALLY PAID" badge (blue background) when:
- Invoice status is "issued", AND
- Total payments > 0, AND
- Remaining balance > 0

This provides clear visual feedback that payment has been received but is not yet complete.

## Payment Table Display

The payments table on the invoice detail page uses a compact style consistent with line items:
- Rows are clickable to edit payments (admin only)
- Edit icon appears on hover in the rightmost column
- Each row shows: payment date, amount, method, and notes
- When invoice is fully paid (status = 'paid'), the "Add Payment" button is hidden

## Payment Workflow (Admin Only)

1. **Add Payment**: Click "Add Payment" button → Modal opens with default amount = remaining balance
2. **Edit Payment**: Click payment row or hover edit icon → Modal opens with current values
3. **Delete Payment**: Open payment modal → Click "Delete" button
4. **Validation**: System prevents overpayment at API level with user-friendly error messages
