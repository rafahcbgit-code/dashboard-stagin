# Contacts User Guide

This guide explains all major features available in the **Contacts** section.

## 1. Contacts Dashboard

The Contacts page is your main workspace for viewing and managing all contact records.

### What you can do
- View contacts in a paginated table.
- Search contacts quickly by name, phone, email, and country-related values.
- Show or hide table columns from the **Columns** menu.
- Open **Advanced Filters** for detailed filtering.
- Open **Groups** to manage and filter by groups.
- Use **Import** to upload contacts through the wizard.
- Use **Export All** to export all contacts that match the current filters.

## 2. Table and Selection

### Row selection
- Select individual contacts using row checkboxes.
- Use header checkbox for page-level selection.
- Use **Select all results** to select contacts across all pages (not only current page).
- Use **Clear Selection** to clear selected contacts and reset applied filters.

### Bulk action bar
When contacts are selected, a bulk action bar appears.

Available actions:
- Delete selected contacts.
- Add/Remove Groups.
- Add/Remove Labels.
- Change Subscription Status.
- Export selected contacts.

## 3. Search and Filters

### Quick search
Use the top search box to find contacts fast.

### Country quick filter
A country phone-code filter is available in the main toolbar for fast filtering by dialing code.

### Advanced Filters
Use Advanced Filters for precise targeting:
- Groups (multi-select).
- Labels (multi-select).
- Subscription status.
- Contact source (`platform`, `imported`, `whatsapp`, `api`).
- Blocked status.
- Country.
- Date range (`created_at` from/to).
- Custom attribute key/value.

Special options inside dropdowns:
- `No Group`
- `No Label`

## 4. Column Visibility

From the **Columns** button, you can show/hide table columns such as:
- Country
- Custom Attributes
- Contact Source
- Created At
- Other standard fields

The selected column layout is stored locally so it remains consistent for the user.

## 5. Contact Source

Each contact has a `contact_source` value:
- `platform`: manually created from the platform.
- `imported`: created through CSV/XLSX import.
- `whatsapp`: created from webhook flow.
- `api`: created through API.

If no source is provided, default behavior is `platform`.

## 6. Contact Blocking

Contacts can be blocked/unblocked.

Blocked contact behavior:
- Outbound messaging is disabled for that contact.
- Template sending is disabled for that contact.
- The chat composer shows a blocked warning state.

You can filter blocked contacts using Advanced Filters.

## 7. Groups Management

From the **Groups** modal you can:
- Create a new group.
- Delete a group.
- Select one or multiple groups for filtering.
- Apply or reset group-based filtering.

The modal supports scrollable group lists and fixed action controls for usability with large datasets.

## 8. Import Contacts Wizard (5 Steps)

Use **Import** to open the Contact Import Wizard.

### Step 1: Upload
- Upload CSV/XLSX file.
- Loading indicator appears while processing and preparing preview.

### Step 2: Mapping
- Map file columns to contact fields.
- Mapping is required before continuing.

### Step 3: Normalize & Clean
- Apply normalization rules (country code handling and formatting cleanup).
- Review invalid rows.
- Delete invalid rows individually or use **Clear All Invalid**.
- Continue only after normalization is applied and validation state is ready.

### Step 4: Groups
Assign groups using one of the following methods:
- Add all valid contacts to selected groups.
- Split contacts into batches by size and auto-create group names using a prefix (example: `group_1`, `group_2`, ...).

Group merge behavior:
- File group values + manually selected groups are merged.
- Existing contact groups are preserved.

### Step 5: Import Report
- Execute import.
- See success/failed counts and created/updated totals.
- Download failure report CSV if needed.
- After completion, execution state is clearly shown and duplicate execution is prevented.

## 9. Duplicate Handling During Import

If an imported phone number already exists:
- Existing contact is updated.
- Existing groups remain.
- New groups are added (merged, not replaced).

## 10. Export Options

### Export selected
Use bulk selection + export action.

### Export all
Use **Export All** to export all contacts matching current filters.

## 11. Editing Contacts

From contact details/edit view, users can:
- Update basic information.
- Update address fields.
- Update groups.
- Update dynamic custom fields (including select-type fields/options).

## 12. Best Practices

- Apply filters first, then use **Select all results** for safe bulk actions.
- Run normalization before group assignment in import workflow.
- Remove invalid rows before final import for cleaner data quality.
- Use Contact Source and Blocked Status filters to support operational teams.
