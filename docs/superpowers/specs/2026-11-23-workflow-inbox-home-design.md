# Internal workflow, live notifications, quick-access home and sidebar — design

Date: 2026-11-23 · Branch: `catalog-redesign`

## Intent

The owner wants paperless work: every document travels electronically from one department to the next, each step
reaches the right people instantly (realtime) and notifies them, and every action has an owner. Example from the owner:
a new production order must reach the production staff at once, with live updates, not on paper.
Alongside it: a very professional quick-access home page, a notification system that really works, and a sidebar
ordered by priority.

## Decisions (owner, 2026-11-23)

- **W-1 Recipient = department inbox + optional person.** A step lands in the department's inbox; every member sees it
  live; one member *takes* it and becomes its owner. The sender may name a specific person instead.
- **W-2 The chain is defined from a settings page** per document type, shipped with editable defaults; no programmer
  needed to change it.
- **W-4 The chain organises, it never forces** (owner, 2026-11-23). The configured steps only *suggest* the next
  department (pre-selected in the «إرسال» dialog, and opened automatically when a status trigger is reached); any user
  may send a document to any department or person at any time, back and forth (e.g. purchasing → sales → purchasing).
  Every hop is recorded in the document's path and notifies its recipient. No step blocks a status change.
- **W-5 Direct notification to a person** (owner, 2026-11-23): from any document (or from the bell) a user sends a
  notification/request to a named person or a whole department — e.g. sales asks a specific purchasing user for
  something — with a short text and an optional link to the document. It arrives live, can be replied to, and is
  kept in both users' history (a lightweight request thread, not a chat app).
- **W-3 One notification system for everything.** Whatever reaches a user — a document, a task, a reassignment, a
  status change, a stock alert, a mention — notifies that user's account instantly, always through the single
  `Modules\Core\Services\NotificationService` (database + Reverb live); no feature sends notifications its own way.

## Approaches considered

1. **Departments as a new entity (chosen — my call).** `core_departments` (name, manager, active) with members
   (`department_user`). Roles stay for permissions; a user may be in several departments.
   *Why:* today's roles mix permissions with parties (Customer, Supplier, Delivery Company); routing work to "the
   Customer role" makes no sense, and permission changes must not move work around.
2. Use Spatie roles as departments — no new table, but couples routing to permissions and to party roles. Rejected.
3. A generic BPM/state-machine package — heavy, and the documents already have their own status enums. Rejected.

## Design

### Data (module Core, generic for any model)
- `core_departments`, `core_department_user` (user_id, department_id, is_manager).
- `core_workflow_steps` — the chain per document type: `document_type` (morph alias), `sequence`, `name`,
  `department_id`, `trigger` (the document status that opens this step, e.g. sales order *confirmed*), `due_hours`
  (optional SLA), `is_active`. Seeded defaults (editable):
  - Sales order: Sales (draft) → Warehouse «تجهيز» (confirmed) → Invoicing (prepared) → Delivery (invoiced).
  - Production order: Sales/Planning → Production (approved) → Warehouse (completed).
  - Purchase order: Purchasing → Warehouse «استلام» (confirmed) → Accounting «فوترة» (received).
  - Consolidated purchase order: Purchasing → Logistics/Clearance → Accounting.
  - Vouchers / returns: Accounting review.
- `core_document_tasks` — one row per step reached by a document: `document` (morph), `step_id`, `department_id`,
  `assigned_to` (nullable), `status` (open, taken, done, returned, cancelled), `sent_by`, `taken_at`, `done_at`,
  `due_at`, `note`. History = these rows + activity log.

### Behaviour
- When a document reaches a step's trigger status (observer, after commit), a task opens for the step's department
  (or the person chosen in the «إرسال» dialog). The previous open task of that document closes as *done*.
- Manual actions on the document page and in the inbox: **إرسال إلى** (department/person, with a note),
  **استلام** (take), **إعادة** (return to the previous step with a reason), **تحويل** (reassign).
- Every transition notifies through the existing `Modules\Core\Services\NotificationService`: department members
  (when unassigned) or the person; the sender is skipped. Overdue tasks notify the department manager.
- The document page shows a **path timeline** (steps, who, when, notes) and the current owner.

### Inbox «مهامي»
A first-class page (sidebar top): tabs *لي* / *قسمي* / *أرسلتها* / *متأخرة*, filters by document type, live updates
over Reverb private channels (`user.{id}`, `department.{id}`) with Laravel Echo — new tasks appear without reload,
with a count badge in the sidebar and the bell.

### Notifications «الإشعارات»
The bell, the list page and the database/Reverb channels already exist. To make them work professionally:
- Run the Reverb server locally and on the server (documented process, supervisor entry); Echo reconnects quietly.
- Internal recipients via the workflow (today `DocumentNotificationObserver` notifies only the party `client_id`).
- Grouping per document, mark-as-read on open, per-user preferences (which events; sound on/off), toast on arrival.

### Quick-access home «الرئيسية»
Replace the generic counters (users by role) with a working start page, every block permission-aware:
- **Quick actions:** «فاتورة بيع جديدة», «طلبية بيع», «عرض سعر», «سند قبض», «سند صرف», «طلبية شراء», «أمر إنتاج» …
  The user pins/unpins and orders them (stored per user).
- **مهامي:** open tasks for me / my department with counts and the 5 most urgent.
- **Recent documents** I opened or created; **global search** (the existing Ctrl+K palette) prominent.
- Role-relevant indicators: today's sales, receivables due, low stock, orders waiting for preparation.

### Calendar «التقويم» (owner, 2026-11-23: everything that has a date appears in the calendar)
One calendar page (month / week / day / agenda) fed by a registry of date sources, each source one class that
returns events for a date range, permission-filtered, each event linking to its document:
expected delivery of sales orders, purchase order / shipment arrival dates, invoice and receivable due dates, cheque
due dates, task due dates (workflow), production order dates, quotation validity, accounting period closings, and
any future dated record. Colour per source, filter chips per source, today's events also on the home page.
A new dated feature registers its source once (documented in `global-components`).
**Its own page** «التقويم» in the sidebar (owner, 2026-11-23), full screen, with the home page keeping a compact
"today / this week" block that links to it. Built on what exists: the home `PurchaseOrderCalendar.vue` (FullCalendar,
loaded lazily) and `Modules\Accounting\Support\ConsolidatedPurchaseOrderCalendar` become the first source of the
registry instead of a one-off widget.

### Sidebar
Ordered by daily priority and grouped by job, not by module:
الرئيسية · مهامي · المبيعات · المشتريات · المخازن · الإنتاج · الأصناف · المحاسبة · التقارير · المستخدمون والأقسام ·
الإعدادات. Rarely-used lookup lists (units, item types, brands, manufacturers, labels …) move into one
«قوائم الأصناف» entry or into Settings; the item tree pages go away with phase 4.

## Constraints
- Project skills: laravel, inertia-views, permissions (new permissions: Department *, Workflow Settings,
  Document Task *), global-components, translations (Modern Standard Arabic).
- New migrations only; never touch the server DB without explicit approval.

## Non-goals
Approval rules with amounts/limits, e-mail/SMS delivery, a visual drag-and-drop workflow designer (the settings page is
an ordered list per document type).

## Slices
1. Departments + members (pages, permissions).
2. Workflow steps settings + defaults; tasks opened by status triggers; notifications to department/person.
3. Inbox page + document path timeline + send/take/return/reassign; Reverb/Echo live updates.
4. Notifications polish (Reverb running, grouping, preferences, toasts).
5. Quick-access home page.
6. Calendar with its date sources.
7. Sidebar regrouping.

## Left to the builder
Exact default chains per document (to be confirmed with the owner on the settings page), icons, SLA defaults.
