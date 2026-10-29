# Events | Migration from NetzpEvents6

Moves events, dates, participants and tickets from NetzpEvents6 to SwkwebEvents. It also updates the Shopware layouts, rules, flows and settings that use them.

## Requirements

- Shopware `~6.7.1` and PHP 8.2 or newer
- SwkwebEvents installed with its database migrations applied. The write commands check for the event tables and the `collect_attendee_names` column.
- The NetzpEvents6 tables in the same Shopware database. Keep NetzpEvents6 installed until the migration is complete.
- `SwkwebEventsNetzpMigration` installed and active. Both event plugins must be inactive during `import` and `switch`.

## Usage

Run the commands from the Shopware project directory. Create a database backup before the cutover. Each `import` replaces existing SwkwebEvents data in a single transaction, and `switch` changes shared Shopware records in a separate transaction.

```bash
bin/console swkweb:events-netzp:check
```

`check` leaves the database untouched and writes a JSON report. Fix any blocking issues, such as open drafts of orders with tickets. If the report asks you to confirm field mappings, review `var/swkweb-events-netzp/field-mapping.json`. Each proposed field groups labels by locale. Correct the groups so that each field contains translations of the same question, then set `"confirmed": true` for each reviewed event. Run `check` again until it reports no blockers.

Deactivate NetzpEvents6 and SwkwebEvents before running the write commands. Review the import and switch reports before activating SwkwebEvents.

```bash
bin/console swkweb:events-netzp:import
bin/console swkweb:events-netzp:switch
bin/console plugin:activate SwkwebEvents
bin/console dal:refresh:index
bin/console cache:clear
```

Verify the event listings, ticket checkout and existing tickets in the storefront. Review any converted rules and ticket flows in the Administration. When you are ready to finish, run `close`. It disables further imports and switches and removes the old ticket document type if no documents still use it. You can then uninstall NetzpEvents6 and the migration plugin.

```bash
bin/console swkweb:events-netzp:close
```

| Option | Commands | Description |
| --- | --- | --- |
| `--report=<path>` | `check`, `import`, `switch` | Overrides the default `check-report.json`, `import-report.json` or `switch-report.json` in `var/swkweb-events-netzp/`. |
| `--mapping=<path>` | `check`, `import` | Overrides `var/swkweb-events-netzp/field-mapping.json`. Pass the same path to both commands. |
| `--remove-app-integration` | `switch` | Deletes the old Events app integration and its custom field set. By default, `switch` reports them and leaves them in place. |

The JSON reports can contain personal data, including attendee names and answers. Limit access to those files. The console summary shows counts and issue codes.

## What is migrated

| NetzpEvents6 | SwkwebEvents |
| --- | --- |
| Events and categories | Records with the same IDs, including translations and existing product, image, category and tag links. |
| Dates | Event slots with the same IDs, times, capacity and translated locations. The import recalculates the booked count from participants. |
| Participants and attendee questions | Participant records with their status, names, answers, ticket numbers and scan codes. Questions become optional text fields. The import can reconstruct missing participants from order data. |
| Ticket order line items | SwkwebEvents event data added to the existing line item payload. |
| CMS event elements and Rule Builder conditions | Event lists and calendars converted in place. Event conditions become SwkwebEvents ticket conditions. |
| Ticket documents, number ranges and flows | Existing documents use the new ticket type and keep their stored PDFs. Ticket numbering continues in one global range. Flows that reference the old ticket type are updated, and the switch creates a flow for automatic PDF generation. |
| Plugin settings | Supported settings, including booking deadlines, availability display and ticket payment requirements. |

## What needs review

- A product can belong to one event. If multiple events share a product, the import drops the link from later events and reports them.
- Orders for deleted dates can keep their event details. The import skips their participant records, and affected ticket PDFs can no longer be downloaded. Ticket documents without a stored file use the new template on first download when their dates still exist.
- Review CMS layouts with language-specific variants, promotions that filter ticket line items, and sales channels with different QR code or anonymous booking settings. Check the JSON reports for the affected records and settings the migration could not carry over.
- Update custom Twig, document and mail templates, snippet overrides and CSS that refer to NetzpEvents6. Review old ticket download and calendar links, including links in mail already sent.
- Customers with NetzpEvents6 tickets in open carts must select the date again.
