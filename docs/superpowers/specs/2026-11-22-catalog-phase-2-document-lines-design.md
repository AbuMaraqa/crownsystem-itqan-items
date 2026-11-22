# Catalogue phase 2 — document lines: price-group lines with colour variants

Follows `2026-11-21-items-catalog-redesign-design.md` (§10 R-1..R-13). Decided with the owner on 2026-11-22
after a council (5 advisors + chairman): hybrid entry, the price group is the main line, master data defined first.

## Intent

A counter clerk adds aluminium the way the old system worked: pick the brand+colour ("الوبين — أبيض") and
list its profiles inside it; a quantity item (accessory, silicone…) is its own line. The fake main item
(`main_product_id`) disappears from documents: the main line *is* the price group (category × colour).

## Decisions

- **D2-1 One search cell, no tabs.** The item cell returns, ranked together:
  1. **price groups** of weight categories (`items_category_finishes`, active) matching the term on category or
     colour name — label «التصنيف — اللون»;
  2. **quantity items** (goods, `pricing_type = simple`, not a variation parent with variations) and their variations;
  3. **weight variations** (profile × colour) matching the term, labelled with their group, so «W45» still finds them.
  The old "Category" tab (a plain category header) is switched off for every document (`groups` default false);
  saved headers on purchase orders still show.
- **D2-2 The main line is the price group.** New nullable column `category_finish_id` (FK, restrict on delete) on
  every `<doc>_items` table with pieces. A group line has `product_id = null`, `pricing_type` weight, quantity =
  kg (actual weight when typed, else the sum of its pieces), unit price = kg price.
- **D2-3 Pieces are colour variations.** Each piece's product is a variation whose `category_finish_id` equals the
  line's. The server refuses a piece from another group, and a new group line without pieces (as today).
  Choosing a weight variation from the search places it inside the line of its group, creating that line if the
  document has none (same as `placeSubItem` today, keyed by group instead of main product).
- **D2-4 Inside a group line**, "add sub-item" searches the profiles of that category that exist in that colour
  (variations with that `category_finish_id`). A profile not made in that colour is not offered.
- **D2-5 Kg price** of a group line for a party, in the order the system already uses:
  1. last posted price of that party for that price group, when `use_last_price` is on;
  2. the party's price list (`PartyPricing::for`) → `items_category_finish_prices` (sell side for sales, buy for purchases);
  3. *(my call)* else the first active price list by `sort_order` (the general list), else 0.
  No party → 0, as today. The line price stays editable. The price is stored on the line (snapshot).
- **D2-6 Stock (slices S2–S4).** A group line has no stock of its own: each piece moves its variation —
  piece count, its weight (the line's actual weight shared by piece weight) and its value share. Variations are
  ordinary stock items (no zero-cost "piece" rule). Cost per kg of a colour = value ÷ weight of its variations.
- **D2-7 Old documents** (main-product lines with pieces) keep their current code path until phase 4.
- **D2-8 Prints** show the group label on the main line and the variations (profile names) as its pieces.

## Slices (each one tested and committed on its own)

- **S1** grid search + group line + pieces + kg price; saving and reopening **sales quotation** and **sales order**
  (no stock movement; reservation of variations by their piece counts).
- **S2** sales invoice + delivery note (stock out per variation, kg true-up).
- **S3** purchase order + purchase invoice + goods receipt (stock in and cost per variation).
- **S4** sales and purchase returns.
- **S5** item card "+ new colour" quick-add (permission `Category Update`, same `CategoryColourService` modal,
  all 4 kg prices required) — council D2.

## Non-goals

Removing `main_product_id` (phase 4), migrating old documents, a separate kg ledger table.
