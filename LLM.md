# Dolibarr MCP Server - LLM Usage Guide

## Overview

This MCP server provides **19 tools** to interact with any Dolibarr ERP instance via its REST API. The tools are designed to work dynamically with any Dolibarr module through Swagger/OpenAPI introspection.

**Tools Summary**:
| Tool | Purpose |
|------|---------|
| `dolibarr_api_explorer` | Discover API endpoints and parameters |
| `dolibarr_list` | List resources with filtering/pagination |
| `dolibarr_get` | Get a single resource by ID |
| `dolibarr_create` | Create a new resource |
| `dolibarr_update` | Update an existing resource |
| `dolibarr_delete` | Delete a resource |
| `dolibarr_action` | Execute workflow actions (validate, close, etc.) |
| `dolibarr_add_line` | Add lines to documents (proposals, orders, invoices) |
| `dolibarr_create_from` | Create document from another (proposal→order→invoice) |
| `dolibarr_link_contact` | Link/unlink contacts to documents with roles |
| `dolibarr_get_contacts` | List contacts linked to a document |
| `dolibarr_add_time_spent` | Add time spent to a project task via `/tasks/{id}/addtimespent` with clear diagnostics for known API failures |
| `dolibarr_documents_list` | List documents attached to an element |
| `dolibarr_documents_upload` | Upload a document to an element |
| `dolibarr_documents_download` | Download a document |
| `dolibarr_documents_builddoc` | Generate PDF for an element |
| `dolibarr_documents_delete` | Delete a document |
| `dolibarr_extrafield_update` | Update an extrafield definition |
| `dolibarr_extrafield_delete` | Delete an extrafield definition |
| `dolibarr_files_create` | Create a text-based file for the user to download |

## ⚠️ MCP Failure Discipline

When a requested Dolibarr operation cannot be completed through the MCP:

1. If the MCP does not support the operation, ask the user before using a script, direct API call, SSH, SQL, or any other bypass.
2. If the MCP should support the operation but fails, document the error in this repository, report it, then fix the MCP or delegate a development session.
3. Never silently bypass the MCP and then present the operation as normal MCP success.

For project task time entries, use `dolibarr_add_time_spent`. If it returns `MCP_API_ENDPOINT_BUG`, the entry was **not confirmed as created**; do not bypass silently.

### Project task creation note

When creating project tasks with `dolibarr_create(resource: "tasks", ...)`, Dolibarr may require an explicit `ref` even if the API explorer only shows a generic `request_data` body.

Known working payload shape:

```json
{
  "fk_project": 17,
  "ref": "TK-LUC-DOLIBARR-002",
  "label": "Short task title",
  "description": "Full task context",
  "progress": 0,
  "status": 1
}
```

Recommended workflow:
1. Resolve the thirdparty and project first.
2. List existing tasks for the project to understand the reference pattern.
3. Create the task with `fk_project`, `ref`, `label`, and `description`.
4. Re-read the project tasks to verify the task is attached.

If a create call returns `Bad Request: ref field missing`, retry with an explicit `ref`; also document the friction under `dev/issues/` if this reveals a missing MCP improvement.

## ⚠️ CRITICAL: Update Behavior — Read Before You Write

The Dolibarr API has **two very different update behaviors** depending on what you're updating. Not understanding this difference **will cause data loss**.

### Entity Updates (thirdparties, invoices, orders, products, contacts...)
**Behavior**: PATCH-like — only the fields you send are modified. Omitted fields **keep their existing values**.
```
# Safe: only updates the name, everything else stays the same
dolibarr_update(resource: "thirdparties", id: 123, data: {"name": "New Name"})
```

### Line Updates (invoice lines, order lines, proposal lines...)
**Behavior**: Full replacement — **ALL fields must be provided**. Any field you omit will be **RESET TO ZERO/EMPTY**. This means if you only send `{"desc": "New label"}`, the price, quantity, VAT rate, and everything else will be erased.

**Mandatory workflow for updating a line**:
1. **READ first**: Use `dolibarr_get` to fetch the parent document and find the current line data
2. **COPY all fields**: Take all existing values from the line (desc, subprice, qty, tva_tx, product_type, remise_percent, etc.)
3. **MERGE your changes**: Replace only the field(s) you want to modify
4. **SEND everything**: Pass the complete field set to `dolibarr_update`

**Example — changing only the description of a line**:
```
# Step 1: Read the invoice to get current line data
dolibarr_get(resource: "invoices", id: 252)
→ Find the line: {"id": 933, "desc": "Old desc", "subprice": 100, "qty": 2, "tva_tx": "21.000", "product_type": 1, "remise_percent": 0, ...}

# Step 2: Send ALL fields with only desc changed
dolibarr_update(resource: "invoices/252/lines", id: 933,
    data: {"desc": "New desc", "subprice": 100, "qty": 2, "tva_tx": "21.000", "product_type": 1, "remise_percent": 0})
```

**Required fields for line updates**: `desc`, `subprice`, `qty`, `tva_tx`, `product_type`. Also include `remise_percent`, `fk_product`, `date_start`, `date_end` if they had values.

---

## ⚠️ Resolving a Reference (ref) to a rowid

Most Dolibarr tools (`dolibarr_get`, `dolibarr_update`, `dolibarr_delete`, `dolibarr_action`, `dolibarr_add_line`, `dolibarr_get_contacts`, `dolibarr_link_contact`, `dolibarr_create_from`) expect a **numeric `rowid`** in the `id` parameter — NOT the human-readable reference shown in the Dolibarr UI.

| Type | What it looks like | Where to use it |
|------|--------------------|-----------------|
| **rowid** (numeric) | `42`, `1`, `1024` | `id` parameter of all CRUD/action/line tools |
| **ref** (textual) | `CO2306-0002`, `F2024-0001`, `PR2503-0017` | Search filter in `dolibarr_list` |

**If you only know the reference, you MUST resolve it first.** Calling `dolibarr_get` with a textual reference in the `id` parameter will fail with a clear error message guiding you to this workflow.

### Pattern

```
1. dolibarr_list(resource: "<resource>", sqlfilters: "(t.ref:=:'<ref-value>')", fields: "id,ref")
   → returns [{"id": 42, "ref": "CO2306-0002"}]
2. Extract the numeric rowid (42) from the result
3. dolibarr_<get|update|delete|action|...>(resource: "<resource>", id: 42, ...)
```

### Examples

```
# User asks: "Show me order CO2306-0002"
1. dolibarr_list(resource: "orders", sqlfilters: "(t.ref:=:'CO2306-0002')", fields: "id,ref,total_ttc")
   → [{"id": 2, "ref": "CO2306-0002", "total_ttc": "6582.40000000"}]
2. dolibarr_get(resource: "orders", id: 2, subresource: "lines")

# User asks: "Validate invoice F2024-0042"
1. dolibarr_list(resource: "invoices", sqlfilters: "(t.ref:=:'F2024-0042')", fields: "id,ref,fk_statut")
   → [{"id": 159, "ref": "F2024-0042", "fk_statut": "0"}]
2. dolibarr_action(resource: "invoices", id: 159, action: "validate")

# User asks: "Add a line to proposal PR2503-0017"
1. dolibarr_list(resource: "proposals", sqlfilters: "(t.ref:=:'PR2503-0017')", fields: "id,ref")
2. dolibarr_add_line(resource: "proposals", id: <id-from-step-1>, data: ...)
```

### Note on temporary references

Newly created documents that haven't been validated yet have a reference like `(PROV12)` (provisional) instead of the final reference. The `(PROV...)` value IS the current ref — search with that exact string including parentheses if needed: `sqlfilters: "(t.ref:=:'(PROV12)')"`.

---

## Tools Reference

### 1. `dolibarr_api_explorer`
**Purpose**: Discover available API endpoints and their parameters.

**Use this FIRST** when you need to:
- List all available modules
- Find endpoints for a specific module
- Get required parameters for an operation

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `action` | string | No | `"modules"` (default), `"endpoints"`, or `"parameters"` |
| `module` | string | For endpoints/parameters | Module name (e.g., `thirdparties`, `invoices`) |
| `endpoint` | string | For parameters | Endpoint path (e.g., `/thirdparties`, `/invoices/{id}`) |
| `method` | string | No | HTTP method for parameters action. Default: `"GET"` |

**Examples**:
```
# List all modules
action: "modules"

# List endpoints for thirdparties module
action: "endpoints", module: "thirdparties"

# Get POST parameters for creating a thirdparty
action: "parameters", module: "thirdparties", endpoint: "/thirdparties", method: "POST"
```

---

### 2. `dolibarr_list`
**Purpose**: List resources from any Dolibarr module with filtering and pagination.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource` | string | Yes | Resource type: `thirdparties`, `invoices`, `products`, `orders`, `contacts`, etc. |
| `filters` | JSON string | No | Filter by field values: `{"client": 1}` for customers only. `{"id": 123}` and `{"rowid": 123}` are normalized to `t.rowid` SQL filters because many Dolibarr list endpoints ignore raw `id` query params. |
| `sqlfilters` | string | No | SQL-style filter: `(t.nom:like:'%test%')` or `(t.fk_soc:=:23183)` |
| `sortfield` | string | No | Field to sort by (SQL column names): `rowid`, `nom`, `datec` (creation), `tms` (modification), `datef` (document date). **Note**: Common aliases like `date_creation` are auto-corrected to `datec` |
| `sortorder` | string | No | `ASC` or `DESC` |
| `limit` | int | No | Max results (default: 50). Use 10-20 for AI chat systems to avoid context overflow |
| `page` | int | No | Page offset for pagination (default: 0) |
| `fields` | string | No | Comma-separated list of fields to return. Reduces response size dramatically. Example: `"id,nom,email,town"`. The `id` field is always included even if not requested. If omitted, all fields are returned. |

**⚠️ IMPORTANT — Use `fields` to reduce context usage**: A single thirdparty object contains 130+ fields. When listing 50 records, this can overwhelm the AI context. Always use `fields` when you don't need all data.

**Common filter patterns**:
```
# List only customers
filters: {"mode": 1}

# List only suppliers
filters: {"mode": 2}

# Get one record by rowid through list
filters: {"id": 123}, fields: "id,name,email,town"

# Search by name (SQL filter)
sqlfilters: (t.nom:like:'%Company%')

# Filter contacts by thirdparty ID
sqlfilters: (t.fk_soc:=:23183)

# Filter by status
filters: {"status": "1"}

# Combine filters + fields for compact results
filters: {"mode": 1}, sqlfilters: (t.nom:like:'%dupont%'), fields: "id,nom,email,town,zip"
```

Use `dolibarr_get(resource: ..., id: ...)` when you already want the full
object for a known rowid. Use `dolibarr_list(..., filters: {"id": ...})`
when you need list-style output, field reduction, or to combine rowid with
other filters.

#### `sqlfilters` Reference

**Syntax**: `(t.field:operator:'value')` — Combine with `AND` / `OR`.

**Operators**: `like`, `=`, `!=`, `<`, `>`, `<=`, `>=`, `is` (null), `isnot` (null)

**Note**: `LIKE` is case-insensitive in most MySQL configurations. The `IN` operator is NOT supported.

**Available fields by module** (these are **SQL column names** for sqlfilters, which differ from API JSON field names):

**⚠️ IMPORTANT**: SQL columns and API JSON fields use different names for some fields:
- Thirdparties: SQL `t.nom` → API JSON `name`
- Products: SQL `t.fk_product_type` → API JSON `type`
- Use SQL names (`t.nom`) in `sqlfilters`, but API names (`name`) in `fields` parameter and in create/update data.

| Module | Fields |
|--------|--------|
| Thirdparties | `t.nom`, `t.name_alias`, `t.email`, `t.phone`, `t.zip`, `t.town`, `t.address`, `t.status`, `t.client`, `t.fournisseur`, `t.code_client`, `t.code_fournisseur`, `t.siren`, `t.siret`, `t.tva_intra`, `t.country_code` |
| Contacts | `t.lastname`, `t.firstname`, `t.email`, `t.phone_pro`, `t.phone_mobile`, `t.fk_soc`, `t.poste` |
| Invoices | `t.ref`, `t.ref_client`, `t.datef`, `t.total_ht`, `t.total_ttc`, `t.fk_statut`, `t.paye`, `t.fk_soc`, `t.date_lim_reglement` |
| Orders | `t.ref`, `t.ref_client`, `t.date_commande`, `t.total_ht`, `t.total_ttc`, `t.fk_statut`, `t.fk_soc` |
| Proposals | `t.ref`, `t.ref_client`, `t.datep`, `t.fin_validite`, `t.total_ht`, `t.total_ttc`, `t.fk_statut`, `t.fk_soc` |
| Products | `t.ref`, `t.label`, `t.price`, `t.tosell`, `t.tobuy`, `t.fk_product_type`, `t.barcode` |
| Bank Accounts | `t.ref`, `t.label`, `t.number`, `t.currency_code`, `t.clos`, `t.courant` |

**Examples**:
```
# Thirdparty: search by partial name + city
sqlfilters: (t.nom:like:'%dupont%') AND (t.town:like:'%Paris%')

# Contact: search by first name OR last name
sqlfilters: (t.lastname:like:'%martin%') OR (t.firstname:like:'%jean%')

# Invoices: unpaid invoices over 1000€ for a specific customer
sqlfilters: (t.fk_statut:=:'1') AND (t.total_ttc:>:'1000') AND (t.fk_soc:=:'12345')

# Products: search label containing "cable" that are on sale
sqlfilters: (t.label:like:'%cable%') AND (t.tosell:=:'1')

# Thirdparty: Belgian companies
sqlfilters: (t.country_code:=:'BE')
```

#### Recommended `fields` per module

| Module | Compact fields | Use case |
|--------|---------------|----------|
| Thirdparties | `id,name,name_alias,email,phone,town,zip,status` | Quick directory lookup. **Note**: use `name` (not `nom`) — the API returns `name` |
| Contacts | `id,lastname,firstname,email,phone_pro,poste` | Contact search |
| Invoices | `id,ref,socid,total_ttc,status,datef,date_lim_reglement` | Invoice overview |
| Orders | `id,ref,socid,total_ttc,status,date_commande` | Order tracking |
| Proposals | `id,ref,socid,total_ttc,status,datep,fin_validite` | Proposal overview |
| Products | `id,ref,label,price,type,status,status_buy` | Product catalog. **Note**: use `type` (not `fk_product_type`) — 0=product, 1=service |
| Bank Accounts | `id,ref,label,number,currency_code,status,balance` | Account overview |

---

### 3. `dolibarr_get`
**Purpose**: Get a single resource by ID.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource` | string | Yes | Resource type |
| `id` | int | Yes | Resource ID |
| `subresource` | string | No | Sub-resource path (e.g., `"lines"` for invoice lines) |
| `fields` | string | No | Comma-separated list of fields to return. Example: `"id,nom,email,town"`. Reduces response size. |

**Examples**:
```
# Get thirdparty details
resource: "thirdparties", id: 23183

# Get thirdparty with only key fields
resource: "thirdparties", id: 23183, fields: "id,name,email,phone,address,zip,town"

# Get invoice with lines
resource: "invoices", id: 1234, subresource: "lines"
```

---

### 4. `dolibarr_create`
**Purpose**: Create a new resource.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource` | string | Yes | Resource type |
| `data` | JSON string | Yes | Resource data as JSON object |

**Common creation patterns**:

**Thirdparty (Customer)**:
```json
{
  "name": "Company Name",
  "client": 1,
  "address": "123 Main Street",
  "zip": "12345",
  "town": "City",
  "country_id": 1,
  "email": "contact@company.com",
  "phone": "0123456789"
}
```

**Thirdparty (Supplier)**:
```json
{
  "name": "Supplier Name",
  "fournisseur": 1,
  "address": "456 Supplier Ave"
}
```

**Contact**:
```json
{
  "socid": 23183,
  "lastname": "Dupont",
  "firstname": "Jean",
  "email": "jean.dupont@company.com",
  "phone_pro": "0123456789",
  "poste": "Director"
}
```

**Product**:
```json
{
  "ref": "PROD-001",
  "label": "Product Name",
  "type": 0,
  "price": 99.99,
  "status": 1
}
```

**Service**:
```json
{
  "ref": "SERV-001",
  "label": "Service Name",
  "type": 1,
  "price": 150.00,
  "status": 1
}
```

**Bank Account**:
```json
{
  "ref": "BNP-001",
  "label": "Compte courant BNP",
  "bank": "BNP Paribas",
  "number": "FR7630004000031234567890143",
  "currency_code": "EUR",
  "courant": 1,
  "clos": 0
}
```
**⚠️ Note**: The `ref` field is **required** for bank account creation (returns error 400 if missing).

---

### 5. `dolibarr_update`
**Purpose**: Update an existing resource.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource` | string | Yes | Resource type (or composite path for lines, e.g., `invoices/252/lines`) |
| `id` | int | Yes | Resource ID (or line ID for composite paths) |
| `data` | JSON string | Yes | Fields to update as JSON object |

**Entity update** (safe partial update):
```
resource: "thirdparties"
id: 23183
data: {"name": "Updated Company Name", "email": "new@email.com"}
→ Only name and email change, all other fields preserved
```

**Line update** (⚠️ must send ALL fields — see "Update Behavior" section above):
```
# WRONG — will reset price, qty, VAT to zero:
resource: "invoices/252/lines", id: 933
data: {"desc": "New description"}

# CORRECT — first read the line, then send all fields:
resource: "invoices/252/lines", id: 933
data: {"desc": "New description", "subprice": 100, "qty": 2, "tva_tx": "21.000", "product_type": 1, "remise_percent": 0}
```

---

### 6. `dolibarr_delete`
**Purpose**: Delete a resource.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource` | string | Yes | Resource type |
| `id` | int | Yes | Resource ID |

**Warning**: Use with caution. Some resources may have dependencies.

---

### 7. `dolibarr_action`
**Purpose**: Execute workflow actions on resources (validate, close, etc.).

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource` | string | Yes | Resource type |
| `id` | int | Yes | Resource ID |
| `action` | string | Yes | Action name |
| `data` | JSON string | No | Additional action parameters |

**Common actions**:
| Resource | Actions |
|----------|---------|
| `invoices` | `validate`, `settopaid`, `setunpaid`, `settodraft` |
| `orders` | `validate`, `close`, `setinvoiced`, `reopen` |
| `proposals` | `validate`, `close`, `settodraft`, `reopen` |
| `thirdparties` | `close`, `reopen` |

---

### 8. `dolibarr_add_line`
**Purpose**: Add product/service lines to commercial documents (proposals, orders, invoices, supplierorders, supplierinvoices).

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource` | string | Yes | Document type: `proposals`, `orders`, `invoices`, `supplierorders`, `supplierinvoices`, `contracts` |
| `id` | int | Yes | Document ID |
| `data` | JSON string | Yes | Line data as JSON object |

**Line data fields**:
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `fk_product` | int | No | Product ID (if linking to existing product) |
| `qty` | float | Yes | Quantity |
| `subprice` | float | Conditional | Unit price HT (required if no fk_product). Auto-mapped to `pu_ht` for supplier documents. |
| `tva_tx` | float | Conditional | VAT rate, e.g., `21.000` (required if no fk_product) |
| `desc` | string | Conditional | Description (required if no fk_product). Auto-mapped to `description` for supplier documents. |
| `product_type` | int | **Yes** | 0 = product, 1 = service. **Always required!** |
| `remise_percent` | float | No | Discount percentage |

**⚠️ IMPORTANT**: The `product_type` field is **always required**, even when linking to a product via `fk_product`. Without it, you'll get a SQL error.

**🔄 Supplier field auto-mapping**: For `supplierinvoices` and `supplierorders`, the server automatically maps `subprice` → `pu_ht` and `desc` → `description`. You can use the same field names for both customer and supplier documents.

**Examples**:
```
# Add a service line (free text)
resource: "proposals"
id: 2939
data: {"desc": "Consulting service", "qty": 1, "subprice": 45.00, "tva_tx": 21.000, "product_type": 1}

# Add a product from catalog
resource: "orders"
id: 20237
data: {"fk_product": 7188, "qty": 5, "product_type": 0}

# Add with discount
resource: "invoices"
id: 19851
data: {"fk_product": 7188, "qty": 10, "product_type": 0, "remise_percent": 10}

# Add a line to a supplier invoice (same fields — auto-mapped)
resource: "supplierinvoices"
id: 42
data: {"desc": "Consulting received", "qty": 2, "subprice": 100, "tva_tx": 21.000, "product_type": 1}
→ Server sends: {"description": "Consulting received", "qty": 2, "pu_ht": 100, "tva_tx": 21.000, "product_type": 1}

# Add a service line to a contract
resource: "contracts"
id: 15
data: {"desc": "Monthly maintenance", "qty": 1, "subprice": 450.00, "tva_tx": 21.000, "product_type": 1}
```

---

### 9. `dolibarr_create_from`
**Purpose**: Create a document from another document (order from proposal, invoice from order). This copies all lines automatically.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource` | string | Yes | Target document type: `orders`, `invoices` |
| `sourceType` | string | Yes | Source document type: `proposal`, `order` |
| `sourceId` | int | Yes | Source document ID |

**Supported conversions**:
| Source Type | Target Resource | Endpoint Used |
|-------------|-----------------|---------------|
| `proposal` or `propal` | `orders` | `/orders/createfromproposal/{id}` |
| `order` or `commande` | `invoices` | `/invoices/createfromorder/{id}` |
| `shipping` | `invoices` | `/invoices/createfromcontract/{id}` |

**Examples**:
```
# Create order from validated proposal
resource: "orders"
sourceType: "proposal"
sourceId: 2939
→ Returns: {"success": true, "id": 20237, "message": "Created orders from proposal #2939"}

# Create invoice from validated order
resource: "invoices"
sourceType: "order"
sourceId: 20237
→ Returns: {"success": true, "id": 19851, "message": "Created invoices from order #20237"}
```

**Note**: The source document must be validated before creating a new document from it. After creation, remember to validate the new document with `dolibarr_action`.

---

### 10. `dolibarr_documents_list`
**Purpose**: List documents attached to a Dolibarr element.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `modulepart` | string | Yes | Module type (see table below) |
| `id` | int | Conditional | Element ID (provide id OR ref) |
| `ref` | string | Conditional | Element reference (provide id OR ref) |
| `sortfield` | string | No | Sort by: `fullname`, `relativename`, `name`, `date`, `size` |
| `sortorder` | string | No | `asc` or `desc` |

**Module types for documents**:
| modulepart | Description |
|------------|-------------|
| `thirdparty` | Third parties |
| `invoice` | Customer invoices |
| `supplier_invoice` | Supplier invoices |
| `order` | Customer orders |
| `supplier_order` | Supplier orders |
| `proposal` | Commercial proposals |
| `product` | Products |
| `member` | Members |
| `project` | Projects |
| `expensereport` | Expense reports |
| `contract` | Contracts |

**Example**:
```
modulepart: "invoice"
id: 19851
→ Returns list of documents attached to invoice ID 19851
```

---

### 11. `dolibarr_documents_upload`
**Purpose**: Upload a document to a Dolibarr element.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `modulepart` | string | Yes | Module type (invoice, order, proposal, product, etc.) |
| `ref` | string | Yes | Element reference (e.g., F2501942, C2518895) |
| `filename` | string | Yes | Filename with extension |
| `filecontent` | string | Yes | File content (text or base64) |
| `fileencoding` | string | No | `""` for text, `"base64"` for binary |
| `subdir` | string | No | Subdirectory within element folder |
| `overwriteifexists` | int | No | 0=no, 1=yes |

**Examples**:
```
# Upload a text file
modulepart: "invoice"
ref: "F2501942"
filename: "notes.txt"
filecontent: "Notes for this invoice..."
fileencoding: ""

# Upload a binary file (base64)
modulepart: "product"
ref: "PROD-001"
filename: "image.png"
filecontent: "iVBORw0KGgoAAAANSUhEUg..."
fileencoding: "base64"
```

---

### 12. `dolibarr_documents_download`
**Purpose**: Download a document from Dolibarr.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `modulepart` | string | Yes | Module type (`facture`, `commande`, `propal`, `produit`, etc.) |
| `original_file` | string | Yes | Relative path: `{ref}/{filename}` |
| `save_to_path` | string | No | Local path to save file to disk. When set, only metadata is returned (no base64 in response). **Recommended for PDFs and large files** to avoid context overload. |

**⚠️ Note**: For download, use French module names: `facture` (not invoice), `commande` (not order), `propal` (not proposal).

**💡 Tip**: Use `save_to_path` for PDFs and large files to avoid flooding the LLM context with base64 data. The file is saved to disk and only the path + size are returned.

**Examples**:
```
# Save to disk (recommended for PDFs/large files)
modulepart: "facture"
original_file: "F2501942/F2501942.pdf"
save_to_path: "/tmp/F2501942.pdf"
→ Returns: {"saved_to": "/tmp/F2501942.pdf", "size": 45230}

# Return base64 in response (small files, text content)
modulepart: "facture"
original_file: "F2501942/F2501942.pdf"
→ Returns: {"content": "base64-encoded-content"}
```

---

### 13. `dolibarr_documents_builddoc`
**Purpose**: Generate/build a PDF document for an element.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `modulepart` | string | Yes | `invoice`, `order`, `proposal`, `contract`, `shipment` |
| `original_file` | string | Yes | Target path: `{ref}/{ref}.pdf` |
| `doctemplate` | string | No | PDF template name (e.g., `crabe`, `sponge`, `azur`) |
| `langcode` | string | No | Language code (default: `fr_FR`) |

**Example**:
```
# Generate invoice PDF
modulepart: "invoice"
original_file: "F2501942/F2501942.pdf"
langcode: "fr_FR"
→ Generates PDF and returns base64-encoded content
```

---

### 14. `dolibarr_documents_delete`
**Purpose**: Delete a document from Dolibarr.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `modulepart` | string | Yes | Module type (`facture`, `produit`, etc.) |
| `original_file` | string | Yes | Relative path: `{ref}/{filename}` |

**Example**:
```
modulepart: "facture"
original_file: "F2501942/notes.txt"
→ Deletes the file
```

---

### 15. `dolibarr_link_contact`
**Purpose**: Link or unlink a contact to/from a document (order, invoice, proposal).

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource` | string | Yes | Document type: `orders`, `invoices`, `proposals`, `supplier_orders`, `supplier_invoices`, `contracts` |
| `id` | int | Yes | Document ID |
| `contactid` | int | Yes | Contact ID to link/unlink |
| `type` | string | Yes | Contact role: `BILLING`, `SHIPPING`, `CUSTOMER` |
| `source` | string | No | `"external"` for customer/supplier contacts, `"internal"` for employees. Default: `"external"` |
| `action` | string | No | `"add"` to link (default), `"remove"` to unlink |

**⚠️ Note**: The `source` parameter is required for proposals. Use `"external"` for customer/supplier contacts.

**Examples**:
```
# Link a contact as billing contact on an invoice
resource: "invoices", id: 1001, contactid: 67890, type: "BILLING"

# Link a contact to a proposal (source required)
resource: "proposals", id: 2939, contactid: 67890, type: "CUSTOMER", source: "external"

# Unlink a contact
resource: "orders", id: 20237, contactid: 67890, type: "SHIPPING", action: "remove"
```

---

### 16. `dolibarr_get_contacts`
**Purpose**: Get all contacts linked to a document with their roles.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `resource` | string | Yes | Document type: `orders`, `invoices`, `proposals`, `supplier_orders`, `supplier_invoices`, `contracts` |
| `id` | int | Yes | Document ID |

**Example**:
```
resource: "proposals", id: 2939
→ Returns list of linked contacts with their roles
```

---

### 17. `dolibarr_extrafield_update`
**Purpose**: Update an extrafield definition. Requires admin permissions.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `elementtype` | string | Yes | Element type: `user`, `thirdparty`, `product`, `commande`, `facture`, `propal`, etc. |
| `attrname` | string | Yes | Attribute name (code) of the extrafield |
| `data` | JSON string | Yes | Fields to update (must include `type` and `size`) |

**⚠️ IMPORTANT**: The `type` and `size` fields are **required** in the data, otherwise the API returns a 500 error.

**Example**:
```
elementtype: "user"
attrname: "custom_field"
data: {"type": "varchar", "label": "Updated Label", "size": "255", "list": "1"}
```

**Available extrafield types**: `varchar`, `int`, `double`, `date`, `datetime`, `boolean`, `sellist`, `text`, `html`

---

### 18. `dolibarr_extrafield_delete`
**Purpose**: Delete an extrafield definition. Requires admin permissions.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `elementtype` | string | Yes | Element type: `user`, `thirdparty`, `product`, etc. |
| `attrname` | string | Yes | Attribute name (code) of the extrafield to delete |

**⚠️ Warning**: This permanently removes the field and all its data.

**Example**:
```
elementtype: "user"
attrname: "custom_field"
→ Deletes the extrafield
```

---

### 19. `dolibarr_files_create`

**Purpose**: Create a text-based file in the user's personal Dalfred storage area. The file becomes downloadable through a forced-attachment link.

**When to use:**
- The user asks for a report, an export, or any text artifact they want to download.
- Examples: "génère-moi un CSV de mes 10 derniers clients", "fais-moi un récap markdown de cette conversation", "produis un rapport HTML avec les chiffres clés".

**When NOT to use:**
- Binary formats (PDF, Excel/XLSX, images) — not supported in the MVP.
- Attaching a file to a specific Dolibarr element (invoice, order…) — use `dolibarr_documents_upload` instead.

**Parameters**:
| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `filename` | string | Yes | Logical filename. Spaces/special chars are sanitised. Any extension in this argument is ignored. |
| `format` | string | Yes | One of: `txt`, `csv`, `md`, `json`, `html` |
| `content` | string | Yes | File content (UTF-8 text) |

**Success response (excerpt)**:
```json
{
  "success": true,
  "filename": "report.csv",
  "size": 1234,
  "download_url": "/custom/dalfred/download.php?f=report.csv",
  "agent_hint": "I created the file: [report.csv](/custom/dalfred/download.php?f=report.csv). Reply to the user including this Markdown link so they can download it."
}
```

**Pattern in your reply to the user (Markdown)**:
```markdown
J'ai créé le rapport : [report.csv](/custom/dalfred/download.php?f=report.csv)
```

The user clicks the link → the file downloads. They can also manage all their generated files at `/custom/dalfred/files.php`.

**Errors (stable codes returned in the `error` field)**:
- `FileGenerationDisabled` — the toolkit is OFF in admin settings.
- `InvalidFormat` — `format` not in the whitelist (`txt`, `csv`, `md`, `json`, `html`).
- `FileTooLarge` — content exceeds the configured max size.
- `InvalidFilename` — name unusable after sanitisation.
- `NotAuthenticated` — the call was not properly authenticated; the user should re-login or the API key is invalid. Rare in practice since the agent is already authenticated.
- `WriteFailed` — the file could not be written (disk error or permissions issue); tell the user to retry and, if the problem persists, ask their administrator to check disk space and folder permissions.

When you receive one of these errors, explain it plainly to the user (don't show JSON). For `FileGenerationDisabled`, tell them to ask their administrator to enable file generation.

---

## Extrafields Management

**Listing extrafields** (admin required):
```
dolibarr_list(resource: "setup/extrafields", filters: {"elementtype": "user"})
→ Returns all extrafields for users
```

**Creating extrafields**:
```
dolibarr_create(
  resource: "setup/extrafields/user/my_field",
  data: {"type": "varchar", "label": "My Field", "size": "255", "pos": "100"}
)
→ Creates a new varchar extrafield for users
```

**Updating extrafields**:
```
dolibarr_extrafield_update(
  elementtype: "user",
  attrname: "my_field",
  data: {"type": "varchar", "label": "Updated Label", "size": "255"}
)
```

**Deleting extrafields**:
```
dolibarr_extrafield_delete(elementtype: "user", attrname: "my_field")
```

---

## Common Modules

| Module | Resource Name | Description |
|--------|--------------|-------------|
| Thirdparties | `thirdparties` | Customers, suppliers, prospects |
| Contacts | `contacts` | Contact persons linked to thirdparties |
| Products | `products` | Physical products |
| Services | `products` (type=1) | Services (same endpoint as products) |
| Invoices | `invoices` | Customer invoices |
| Orders | `orders` | Customer orders |
| Proposals | `proposals` | Commercial proposals/quotes |
| Supplier Invoices | `supplierinvoices` | Supplier invoices |
| Supplier Orders | `supplierorders` | Supplier orders |
| Categories | `categories` | Product/customer categories |
| Users | `users` | System users |
| Projects | `projects` | Project management |
| Bank Accounts | `bankaccounts` | Bank/cash accounts and transactions |
| Expense Reports | `expensereports` | Employee expense reports |
| Agenda Events | `agendaevents` | Calendar events and actions |
| Contracts | `contracts` | Service contracts |
| Tickets | `tickets` | Support/helpdesk tickets |

---

## Bank Accounts Module

The `bankaccounts` resource provides full access to bank/cash accounts, their transaction lines, balances, and internal wire transfers.

### List bank accounts
```
dolibarr_list(resource: "bankaccounts", fields: "id,ref,label,number,currency_code,status,balance")
```

### Get account details
```
dolibarr_get(resource: "bankaccounts", id: 3)
```

### Get current account balance
```
dolibarr_get(resource: "bankaccounts", id: 3, subresource: "balance")
→ Returns the current balance (excluding future-dated operations)
```

### List bank transactions (account lines)
```
dolibarr_get(resource: "bankaccounts", id: 3, subresource: "lines")
→ Returns all bank statement lines for this account
```

**⚠️ Note**: The `lines` endpoint can return a large number of records. When possible, use the MySQL Toolkit with SQL filters for date ranges or amounts to narrow results.

### Add a bank transaction line
```
dolibarr_create(resource: "bankaccounts/3/lines", data: {
    "date": 1708387200,
    "type": "VIR",
    "label": "Payment received from client",
    "amount": 1500.00
})
→ Returns the new line ID
```

**Payment types**: `VIR` (wire transfer), `PRE` (direct debit), `LIQ` (cash), `CB` (credit card), `CHQ` (cheque), `VAD` (online payment), `TYP` (other)

### Create internal wire transfer between accounts
```
dolibarr_create(resource: "bankaccounts/transfer", data: {
    "bankaccount_from_id": 3,
    "bankaccount_to_id": 5,
    "date": 1708387200,
    "description": "Monthly transfer to savings",
    "amount": 5000.00
})
→ Creates matching debit/credit lines in both accounts
```

**Note**: If accounts have different currencies, you must provide `amount_to` for the destination amount.

### Get links for a bank line
```
dolibarr_get(resource: "bankaccounts", id: 3, subresource: "lines/456/links")
→ Returns linked documents (invoices, payments, etc.) for this bank line
```

### Bank Account Key Fields
| Field | Type | Description |
|-------|------|-------------|
| `ref` | string | Account reference code |
| `label` | string | Account label/name |
| `number` | string | Bank account number (IBAN) |
| `currency_code` | string | Currency code (EUR, USD, etc.) |
| `status` | int | 0=closed, 1=open |
| `balance` | float | Current balance (read-only) |
| `bank` | string | Bank name |
| `iban_prefix` | string | IBAN |
| `bic` | string | BIC/SWIFT code |
| `type` | int | 1=current, 2=savings, 0=cash |

### Bank Transaction Line Key Fields
| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Line ID |
| `date` | int | Transaction date (Unix timestamp) |
| `type` | string | Payment type (VIR, PRE, LIQ, CB, CHQ, VAD, TYP) |
| `label` | string | Transaction description |
| `amount` | float | Amount (positive=credit, negative=debit) |
| `num_releve` | string | Bank statement number |
| `num_chq` | string | Cheque number |
| `fk_account` | int | Parent bank account ID |

### Bank Account SQL Filter Fields
| Field | Description |
|-------|-------------|
| `t.ref` | Account reference |
| `t.label` | Account label |
| `t.number` | Account number |
| `t.currency_code` | Currency code |
| `t.clos` | Closed status (0=open, 1=closed) |
| `t.courant` | Account type (1=current, 2=savings, 0=cash) |

---

## Payments — Recording Payments on Invoices

Dolibarr provides API endpoints to record payments on both customer and supplier invoices. Payments are created via a POST action on the invoice endpoint.

### Record a payment on a customer invoice
```
dolibarr_action(resource: "invoices", id: 19851, action: "payments",
    data: {
        "datepaye": 1740787200,
        "paymentid": 2,
        "closepaidinvoices": "yes",
        "accountid": 1,
        "num_payment": "VIR-2025-001",
        "comment": "Wire transfer received"
    })
→ Returns: payment ID
```

### Record a payment on a supplier invoice
```
dolibarr_action(resource: "supplierinvoices", id: 4, action: "payments",
    data: {
        "datepaye": 1740787200,
        "payment_mode_id": 2,
        "closepaidinvoices": "yes",
        "accountid": 1,
        "num_payment": "VIR-2025-002",
        "comment": "Wire transfer sent"
    })
→ Returns: payment ID
```

### List payments on an invoice
```
# Customer invoice payments
dolibarr_list(resource: "invoices/{id}/payments")

# Supplier invoice payments
dolibarr_list(resource: "supplierinvoices/{id}/payments")
```

### Payment Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `datepaye` | timestamp | **Yes** | Payment date as Unix timestamp (seconds) |
| `paymentid` (customer) / `payment_mode_id` (supplier) | int | **Yes** | Payment mode ID from `llx_c_paiement` table |
| `closepaidinvoices` | string | **Yes** | `"yes"` to auto-close invoice if fully paid, `"no"` to keep open |
| `accountid` | int | **Yes** | Bank account ID (from `bankaccounts` resource) |
| `num_payment` | string | No | Payment reference number |
| `comment` | string | No | Payment note/comment |
| `chqemetteur` | string | No | Cheque issuer name (required if payment mode is CHQ) |
| `chqbank` | string | No | Cheque issuer bank name |
| `amount` | float | No | Amount to pay (supplier invoices only). If omitted, pays the full remaining balance |

**⚠️ IMPORTANT — Parameter name difference**: Customer invoices use `paymentid` for the payment mode, while supplier invoices use `payment_mode_id`. Using the wrong name will result in a 400 error.

### Payment Mode IDs

Payment modes are stored in `llx_c_paiement`. Common IDs:
| ID | Code | Description |
|----|------|-------------|
| 2 | `VIR` | Wire transfer (virement) |
| 3 | `PRE` | Direct debit (prélèvement) |
| 4 | `LIQ` | Cash |
| 6 | `CB` | Credit card |
| 7 | `CHQ` | Cheque |

**Tip**: Use `dolibarr_api_explorer(resource: "setup", action: "endpoints")` then look for payment type dictionaries, or query the `llx_c_paiement` table to find active payment modes for your Dolibarr instance.

---

## Thirdparties — Advanced Sub-Endpoints

The `thirdparties` resource provides sub-endpoints for financial summaries, bank accounts, categories, and sales representatives.

### Outstanding amounts (invoices, orders, proposals)
```
# Get unpaid invoices for a customer
dolibarr_get(resource: "thirdparties", id: 123, subresource: "outstandinginvoices")
→ Returns: {"opened": [...invoices...], "total_opened": 5420.50, "total_opened_late": 1200.00}

# Get unpaid invoices for a supplier (same endpoint, add ?mode=supplier)
dolibarr_get(resource: "thirdparties", id: 123, subresource: "outstandinginvoices?mode=supplier")

# Get outstanding orders
dolibarr_get(resource: "thirdparties", id: 123, subresource: "outstandingorders")

# Get outstanding proposals
dolibarr_get(resource: "thirdparties", id: 123, subresource: "outstandingproposals")
```

**Use case**: When user asks "how much does customer X owe me?" → use `outstandinginvoices`. For "what's pending with supplier Y?" → use `outstandinginvoices?mode=supplier`.

### Thirdparty bank accounts (RIB/IBAN)
```
# List bank accounts of a thirdparty
dolibarr_get(resource: "thirdparties", id: 123, subresource: "bankaccounts")
→ Returns: [{id, label, bank, bic, iban, default_rib, rum, ...}]

# Add a bank account
dolibarr_action(resource: "thirdparties", id: 123, action: "bankaccounts",
    data: {"bank": "BNP Paribas", "iban": "BE68539007547034", "bic": "BNAGBEBB", "label": "Compte principal", "proprio": "ACME Corp"})

# Update a bank account
dolibarr_update(resource: "thirdparties/123/bankaccounts", id: 456, data: {"label": "Nouveau label"})

# Delete a bank account
dolibarr_delete(resource: "thirdparties/123/bankaccounts", id: 456)
```

### Thirdparty categories
```
# Get categories of a customer
dolibarr_get(resource: "thirdparties", id: 123, subresource: "categories")

# Add category to a customer (PUT, not POST)
dolibarr_update(resource: "thirdparties/123/categories", id: 5)

# Remove category from a customer
dolibarr_delete(resource: "thirdparties/123/categories", id: 5)

# Get/add/remove supplier categories (same pattern)
dolibarr_get(resource: "thirdparties", id: 123, subresource: "supplier_categories")
dolibarr_update(resource: "thirdparties/123/supplier_categories", id: 5)
dolibarr_delete(resource: "thirdparties/123/supplier_categories", id: 5)
```

### Sales representatives
```
# List sales reps assigned to a thirdparty
dolibarr_get(resource: "thirdparties", id: 123, subresource: "representatives")

# Add a sales rep
dolibarr_action(resource: "thirdparties", id: 123, action: "representative/456")

# Remove a sales rep
dolibarr_delete(resource: "thirdparties/123/representative", id: 456)
```

### Other useful sub-endpoints
```
# Get fixed-amount discounts available for a thirdparty
dolibarr_get(resource: "thirdparties", id: 123, subresource: "fixedamountdiscounts")

# Get invoices eligible for credit note
dolibarr_get(resource: "thirdparties", id: 123, subresource: "getinvoicesqualifiedforcreditnote")

# Get invoices eligible for replacement
dolibarr_get(resource: "thirdparties", id: 123, subresource: "getinvoicesqualifiedforreplacement")

# Merge two thirdparties (moves all data from idtodelete into id)
dolibarr_update(resource: "thirdparties/123/merge", id: 456)
```

---

## Setup & Dictionaries — Reference Data Lookup

The `setup` resource provides access to Dolibarr's reference data dictionaries. Use these to look up IDs for payment types, countries, payment terms, etc. instead of hardcoding values.

### Dictionary endpoints
```
# Payment types (modes de paiement)
dolibarr_list(resource: "setup/dictionary/payment_types")
→ Returns: [{id, code, label, type, active}, ...] — e.g., {id: 2, code: "VIR", label: "Transfer"}

# Payment terms (conditions de règlement)
dolibarr_list(resource: "setup/dictionary/payment_terms")
→ Returns: [{id, code, label, active}, ...] — e.g., {id: 1, code: "RECEP", label: "Due upon receipt"}

# Countries
dolibarr_list(resource: "setup/dictionary/countries")
→ Returns: [{id, code, code_iso, label, active}, ...]

# Get a specific country by code
dolibarr_get(resource: "setup/dictionary/countries/byCode", id: "BE")

# Currencies
dolibarr_list(resource: "setup/dictionary/currencies")

# Civilities (Mr, Mrs, etc.)
dolibarr_list(resource: "setup/dictionary/civilities")

# Shipping methods
dolibarr_list(resource: "setup/dictionary/shipping_methods")

# Units of measure
dolibarr_list(resource: "setup/dictionary/units")

# Legal forms (SA, SARL, etc.)
dolibarr_list(resource: "setup/dictionary/legal_form")

# Contact types (roles: billing, shipping, etc.)
dolibarr_list(resource: "setup/dictionary/contact_types")

# Event types (agenda event categories)
dolibarr_list(resource: "setup/dictionary/event_types")

# Expense report types
dolibarr_list(resource: "setup/dictionary/expensereport_types")

# Ticket categories/types/severities
dolibarr_list(resource: "setup/dictionary/ticket_categories")
dolibarr_list(resource: "setup/dictionary/ticket_types")
dolibarr_list(resource: "setup/dictionary/ticket_severities")

# Incoterms
dolibarr_list(resource: "setup/dictionary/incoterms")

# Regions and states/provinces
dolibarr_list(resource: "setup/dictionary/regions")
dolibarr_list(resource: "setup/dictionary/states")
dolibarr_get(resource: "setup/dictionary/states/byCode", id: "BRU")
```

### System configuration
```
# Get company info (name, address, VAT number, etc.)
dolibarr_list(resource: "setup/company")

# Get enabled modules
dolibarr_list(resource: "setup/modules")

# Get a specific configuration value
dolibarr_get(resource: "setup/conf", id: "MAIN_LANG_DEFAULT")
```

### When to use dictionaries
- **Creating invoices**: look up `payment_terms` for `cond_reglement_id`, `payment_types` for payment mode
- **Creating thirdparties**: look up `countries` for `country_id`, `legal_form` for `forme_juridique_code`
- **Managing contacts**: look up `civilities` for `civility_id`, `contact_types` for role codes
- **Filtering**: look up dictionary IDs instead of guessing them

---

## Products — Advanced Sub-Endpoints

The `products` resource provides sub-endpoints for stock levels, supplier pricing, product variants, and bundled products.

### Stock levels by warehouse
```
# Get stock levels for a product across all warehouses
dolibarr_get(resource: "products", id: 7188, subresource: "stock")
→ Returns: {stock_reel: 150, warehouses: [{id, label, stock_reel, pmp}, ...]}
```

### Supplier purchase prices
```
# List supplier prices for a product
dolibarr_get(resource: "products", id: 7188, subresource: "purchase_prices")
→ Returns: [{fk_soc, ref_fourn, price, quantity, tva_tx, ...}]

# Add a supplier price
dolibarr_action(resource: "products", id: 7188, action: "purchase_prices",
    data: {"fk_soc": 456, "ref_fourn": "SUPP-REF-001", "price": 12.50, "quantity": 1, "tva_tx": 21})

# Delete a supplier price
dolibarr_delete(resource: "products/7188/purchase_prices", id: 789)
```

### Product variants and attributes
```
# List variants of a product
dolibarr_get(resource: "products", id: 7188, subresource: "variants")

# List all product attributes (colors, sizes, etc.)
dolibarr_list(resource: "products/attributes")
```

### Sub-products (kits/bundles)
```
# List sub-products of a bundled product
dolibarr_get(resource: "products", id: 7188, subresource: "subproducts")

# Add a sub-product to a bundle
dolibarr_action(resource: "products", id: 7188, action: "subproducts/add",
    data: {"subproduct_id": 100, "qty": 2})

# Remove a sub-product from a bundle
dolibarr_delete(resource: "products/7188/subproducts/remove", id: 100)
```

### Multi-price system
```
# Get selling prices per customer segment
dolibarr_get(resource: "products", id: 7188, subresource: "selling_multiprices/per_segment")

# Get selling prices per customer
dolibarr_get(resource: "products", id: 7188, subresource: "selling_multiprices/per_customer")

# Get quantity-based pricing tiers
dolibarr_get(resource: "products", id: 7188, subresource: "selling_multiprices/per_quantity")
```

---

## Invoices & Orders — Advanced Sub-Endpoints

### Distributed payment (pay multiple invoices at once)
```
# Pay multiple customer invoices with a single payment
dolibarr_action(resource: "invoices", id: 0, action: "paymentsdistributed",
    data: {
        "arrayofamounts": {"19851": "500.00", "19852": "300.00"},
        "datepaye": 1764288000,
        "paymentid": 2,
        "closepaidinvoices": "yes",
        "accountid": 1
    })
```

### Invoice status management
```
# Mark invoice as paid manually (without recording a payment)
dolibarr_action(resource: "invoices", id: 19851, action: "settopaid")

# Mark paid invoice back to unpaid
dolibarr_action(resource: "invoices", id: 19851, action: "settounpaid")

# Apply a credit note to an invoice
dolibarr_action(resource: "invoices", id: 19851, action: "usecreditnote/{discountid}")

# Apply a fixed discount to an invoice
dolibarr_action(resource: "invoices", id: 19851, action: "usediscount/{discountid}")

# Mark invoice as credit available for reuse
dolibarr_action(resource: "invoices", id: 19851, action: "markAsCreditAvailable")

# Get discount info for an invoice
dolibarr_get(resource: "invoices", id: 19851, subresource: "discount")
```

### Supplier order workflow
```
# Approve a supplier order
dolibarr_action(resource: "supplierorders", id: 100, action: "approve")

# Create reception from supplier order
dolibarr_action(resource: "supplierorders", id: 100, action: "receive",
    data: {"closeopenorder": 1, "comment": "Goods received"})
```

### Order shipments
```
# Get shipments linked to a customer order
dolibarr_get(resource: "orders", id: 200, subresource: "shipment")

# Create a shipment from an order (ship from specific warehouse)
dolibarr_action(resource: "orders", id: 200, action: "shipment/{warehouse_id}")
```

---

## Version Compatibility Notes

Some API endpoints are not available in all Dolibarr versions. The Dolibarr version is injected in the system prompt. If you receive a 404 error on an endpoint, it may not exist in the current version.

### General compatibility
- **v18+**: Core CRUD on thirdparties, invoices, orders, proposals, products, contacts, bankaccounts
- **v19+**: Supplier proposals, enhanced line management, improved extrafields API
- **v20+**: Knowledge management, partnerships, enhanced ticket system
- **v21+**: Payments endpoint on invoices/supplierinvoices, enhanced bankaccounts with balance/transfer, recruitment module

### Fallback strategy
If an endpoint returns 404:
1. Try using `dolibarr_api_explorer` to check if the endpoint exists in this version
2. If not available, try the MySQL Toolkit as a read-only alternative
3. Inform the user that the feature requires a newer Dolibarr version

---

## Workflow Examples

### Create a customer with contact
```
1. dolibarr_create(resource: "thirdparties", data: {"name": "ACME Corp", "client": 1, ...})
   → Returns: {"success": true, "id": 12345}

2. dolibarr_create(resource: "contacts", data: {"socid": 12345, "lastname": "Smith", ...})
   → Returns: {"success": true, "id": 67890}
```

### Create and validate an invoice
```
1. dolibarr_create(resource: "invoices", data: {"socid": 12345, ...})
   → Returns: {"success": true, "id": 1001}

2. dolibarr_action(resource: "invoices", id: 1001, action: "validate")
   → Validates the invoice
```

### Complete Commercial Workflow: Proposal → Order → Invoice
```
# Step 1: Create a proposal for a customer
dolibarr_create(resource: "proposals", data: {"socid": 23183})
→ Returns: {"success": true, "id": 2939}

# Step 2: Add lines to the proposal
dolibarr_add_line(resource: "proposals", id: 2939,
    data: {"desc": "Consulting", "qty": 1, "subprice": 45.00, "tva_tx": 21.000, "product_type": 1})
→ Line added

dolibarr_add_line(resource: "proposals", id: 2939,
    data: {"fk_product": 7188, "qty": 5, "product_type": 0})
→ Line added

# Step 3: Validate the proposal
dolibarr_action(resource: "proposals", id: 2939, action: "validate")
→ Proposal validated with ref "O2502672"

# Step 4: Create order from proposal (copies all lines automatically)
dolibarr_create_from(resource: "orders", sourceType: "proposal", sourceId: 2939)
→ Returns: {"success": true, "id": 20237}

# Step 5: Validate the order
dolibarr_action(resource: "orders", id: 20237, action: "validate")
→ Order validated with ref "C2518895"

# Step 6: Create invoice from order (copies all lines automatically)
dolibarr_create_from(resource: "invoices", sourceType: "order", sourceId: 20237)
→ Returns: {"success": true, "id": 19851}

# Step 7: Validate the invoice
dolibarr_action(resource: "invoices", id: 19851, action: "validate")
→ Invoice validated with ref "F2501942"

# Step 8: Record payment on the invoice
dolibarr_action(resource: "invoices", id: 19851, action: "payments",
    data: {"datepaye": 1764288000, "paymentid": 2, "closepaidinvoices": "yes", "accountid": 1})
→ Payment recorded, invoice marked as paid
```

### Create and pay a supplier invoice
```
# Step 1: Create a supplier invoice
dolibarr_create(resource: "supplierinvoices", data: {
    "socid": 23456, "ref_supplier": "FOURNISSEUR-2025-001",
    "date": 1764288000, "cond_reglement_id": 1
})
→ Returns: {"success": true, "id": 4}

# Step 2: Add lines to the supplier invoice
dolibarr_add_line(resource: "supplierinvoices", id: 4,
    data: {"desc": "Office supplies", "qty": 10, "subprice": 25.00, "tva_tx": 21.000, "product_type": 0})
→ Line added

# Step 3: Validate the supplier invoice
dolibarr_action(resource: "supplierinvoices", id: 4, action: "validate")
→ Supplier invoice validated with ref "SI2602-0001"

# Step 4: Record payment on the supplier invoice
dolibarr_action(resource: "supplierinvoices", id: 4, action: "payments",
    data: {"datepaye": 1764288000, "payment_mode_id": 2, "closepaidinvoices": "yes", "accountid": 1})
→ Payment recorded, supplier invoice marked as paid
```

### Search and filter
```
# Find all customers with "test" in name (compact response)
dolibarr_list(resource: "thirdparties", filters: {"mode": 1}, sqlfilters: "(t.nom:like:'%test%')", fields: "id,name,email,town")

# Get all contacts for a specific company
dolibarr_list(resource: "contacts", sqlfilters: "(t.fk_soc:=:12345)", fields: "id,lastname,firstname,email,phone_pro")

# Find Belgian suppliers
dolibarr_list(resource: "thirdparties", filters: {"mode": 2}, sqlfilters: "(t.country_code:=:'BE')", fields: "id,name,town,tva_intra")

# Unpaid invoices over 500€
dolibarr_list(resource: "invoices", sqlfilters: "(t.fk_statut:=:'1') AND (t.total_ttc:>:'500')", fields: "id,ref,socid,total_ttc,datef,date_lim_reglement")
```

---

## Error Handling

The tools return clear error messages:

| HTTP Code | Meaning | Example |
|-----------|---------|---------|
| 400 | Bad Request | `"name field missing"` - Required field not provided |
| 401 | Unauthorized | Invalid API key |
| 403 | Forbidden | Insufficient permissions |
| 404 | Not Found | `"Thirdparty not found"` - Resource doesn't exist |
| 500 | Server Error | Internal Dolibarr error |
| 501 | Not Implemented | `"API not found"` - Invalid module name |

---

## Best Practices

1. **Read before updating lines**: Line updates (PUT on composite paths like `invoices/{id}/lines/{lineId}`) use full replacement — omitted fields are reset to zero. **Always** fetch the document first with `dolibarr_get`, copy all existing line field values, merge your changes, and send the complete data. This is the most common source of data loss.

2. **Always explore first**: Use `dolibarr_api_explorer` to discover available endpoints before creating/updating resources.

3. **Check required fields**: Use `dolibarr_api_explorer` with `action: "parameters"` to find required fields for POST operations.

4. **Use filters efficiently**: Prefer `sqlfilters` for complex queries, `filters` for simple field matching.

5. **Always use `fields` for listings**: Each Dolibarr object contains 100+ fields. Use `fields` to request only what you need, saving tokens and context space. Example: `fields: "id,name,email,town"`.

6. **Validate before operations**: For invoices/orders, create in draft, verify data, then validate with `dolibarr_action`.

7. **Handle pagination**: For large datasets, use `limit` and `page` parameters.

8. **Link resources correctly**: When creating contacts, always provide `socid` (thirdparty ID). For invoice lines, provide `fk_product`.

---

## Field Reference

### Thirdparty Key Fields
| Field | Type | Description |
|-------|------|-------------|
| `name` | string | **Required**. Company name |
| `client` | int | 1 = customer, 0 = not a customer |
| `fournisseur` | int | 1 = supplier, 0 = not a supplier |
| `address` | string | Street address |
| `zip` | string | Postal code |
| `town` | string | City |
| `country_id` | int | Country ID (1=France, 2=Belgium, etc.) |
| `email` | string | Email address |
| `phone` | string | Phone number |
| `status` | string | "1" = active, "0" = closed |

### Contact Key Fields
| Field | Type | Description |
|-------|------|-------------|
| `socid` | int | **Required**. Parent thirdparty ID |
| `lastname` | string | **Required**. Last name |
| `firstname` | string | First name |
| `email` | string | Email address |
| `phone_pro` | string | Professional phone |
| `poste` | string | Job title/position |

### Product Key Fields
| Field | Type | Description |
|-------|------|-------------|
| `ref` | string | **Required**. Product reference |
| `label` | string | **Required**. Product name |
| `type` | int | 0 = product, 1 = service |
| `price` | float | Selling price (excl. tax) |
| `status` | int | 1 = on sale, 0 = not on sale |
| `status_buy` | int | 1 = on purchase, 0 = not |

### Proposal Key Fields
| Field | Type | Description |
|-------|------|-------------|
| `socid` | int | **Required**. Customer ID (thirdparty) |
| `ref` | string | Reference (auto-generated on validation) |
| `ref_client` | string | Customer's reference |
| `date` | int | Proposal date (Unix timestamp) |
| `fin_validite` | int | Validity end date (Unix timestamp) |
| `cond_reglement_id` | int | Payment terms ID |
| `mode_reglement_id` | int | Payment mode ID |
| `note_public` | string | Public note (visible on PDF) |
| `note_private` | string | Private note (internal) |
| `status` | string | 0=draft, 1=validated, 2=signed, 3=not signed, 4=billed |

### Order Key Fields
| Field | Type | Description |
|-------|------|-------------|
| `socid` | int | **Required**. Customer ID (thirdparty) |
| `ref` | string | Reference (auto-generated on validation) |
| `ref_client` | string | Customer's reference |
| `date` | int | Order date (Unix timestamp) |
| `date_livraison` | int | Delivery date (Unix timestamp) |
| `cond_reglement_id` | int | Payment terms ID |
| `mode_reglement_id` | int | Payment mode ID |
| `note_public` | string | Public note |
| `note_private` | string | Private note |
| `status` | string | 0=draft, 1=validated, 2=shipped partial, 3=delivered, -1=canceled |

### Invoice Key Fields
| Field | Type | Description |
|-------|------|-------------|
| `socid` | int | **Required**. Customer ID (thirdparty) |
| `ref` | string | Reference (auto-generated on validation) |
| `ref_client` | string | Customer's reference |
| `date` | int | Invoice date (Unix timestamp) |
| `date_lim_reglement` | int | Payment due date (Unix timestamp) |
| `cond_reglement_id` | int | Payment terms ID |
| `mode_reglement_id` | int | Payment mode ID |
| `note_public` | string | Public note |
| `note_private` | string | Private note |
| `status` | string | 0=draft, 1=validated, 2=paid partial, 3=paid, -1=canceled |

### Document Line Key Fields
| Field | Type | Description |
|-------|------|-------------|
| `fk_product` | int | Product ID (optional, for catalog items) |
| `desc` | string | Line description |
| `qty` | float | Quantity |
| `subprice` | float | Unit price HT |
| `tva_tx` | float | VAT rate (e.g., 21.000) |
| `product_type` | int | **Required**. 0 = product, 1 = service |
| `remise_percent` | float | Discount percentage |
| `total_ht` | float | Total excl. tax (calculated) |
| `total_tva` | float | Total VAT (calculated) |
| `total_ttc` | float | Total incl. tax (calculated) |

### Calculated Totals (Read-only)
These fields are automatically calculated by Dolibarr:
| Field | Description |
|-------|-------------|
| `total_ht` | Total excluding tax |
| `total_tva` | Total VAT amount |
| `total_ttc` | Total including tax |
| `total_localtax1` | Local tax 1 (if applicable) |
| `total_localtax2` | Local tax 2 (if applicable) |

### SQL Column Names for Sorting (sortfield parameter)

**⚠️ IMPORTANT**: Dolibarr's API returns JSON field names that differ from the actual SQL column names used for sorting. The MCP server auto-corrects common mistakes, but here's the reference:

| JSON field name | SQL column (use for sortfield) | Description |
|-----------------|-------------------------------|-------------|
| `date_creation` | `datec` | Record creation date |
| `date_modification` | `tms` | Last modification timestamp |
| `date` / `datef` | `datef` | Document date (invoice, order, proposal) |
| `date_lim_reglement` | `date_lim_reglement` | Payment due date |
| `date_valid` | `date_valid` | Validation date |
| `rowid` / `id` | `rowid` | Record ID |
| `ref` | `ref` | Document reference |
| `nom` / `name` | `nom` | Name (thirdparties) |
| `total_ttc` | `total_ttc` | Total amount including tax |
| `status` / `statut` | `fk_statut` | Status code |

**Auto-corrected aliases**: The following are automatically converted:
- `date_creation`, `created`, `created_at` → `datec`
- `date_modification`, `updated`, `updated_at` → `tms`
- `date_invoice`, `invoice_date` → `datef`

---

## API Quirks & Known Limitations

These are Dolibarr API behaviors, not MCP server bugs.

### Line Management
- **Add line**: Use `dolibarr_add_line`
- **Update line**: Use `dolibarr_update` with composite path (e.g., `resource: "invoices/252/lines"`, `id: 933`). ⚠️ **ALL fields must be sent** — any omitted field is reset to zero/empty (not just `tva_tx`, but also `subprice`, `qty`, `desc`, `product_type`, `remise_percent`, etc.). **Always read the line first** with `dolibarr_get`, then merge your changes with all existing values before updating.
- **Delete line**: Use `dolibarr_delete` with composite path (e.g., `resource: "invoices/252/lines"`, `id: 935`)
- **`dolibarr_update` ignores `lines` array**: Lines in the payload of a parent document update are silently ignored. Manage lines separately.

### Supplier Documents — Field Name Differences (auto-handled)
The Dolibarr API uses **different field names** for supplier documents (`supplierinvoices`, `supplierorders`) compared to customer documents:
- **Price**: Supplier API expects `pu_ht` instead of `subprice`
- **Description**: Supplier API expects `description` instead of `desc`
- **Response**: `POST /supplierinvoices/{id}/lines` returns an **empty body** instead of the line ID

The MCP server **automatically maps** `subprice` → `pu_ht` and `desc` → `description` for supplier resources, so you can use the same field names for both customer and supplier documents. If you provide the supplier field names directly (`pu_ht`, `description`), they are used as-is.

### Payment Parameter Name Differences (NOT auto-handled)
The payment mode ID parameter has **different names** depending on the invoice type:
- **Customer invoices** (`invoices`): use `paymentid`
- **Supplier invoices** (`supplierinvoices`): use `payment_mode_id`
This is NOT auto-mapped by the MCP server — you must use the correct parameter name for each type.

### Creating Lines with Price 0€
`subprice: 0` causes HTTP 500. Use `subprice: 1` with `remise_percent: 100` for free items.

### Creating Orders with Multiple Lines
Creating an order with 2+ lines in the `lines` array causes HTTP 500. Create with 1 line, then use `dolibarr_add_line` for additional lines.

### Trigger Control (`notrigger` parameter)
- `notrigger: 0` → Execute triggers (emails, stock updates, accounting entries, webhooks)
- `notrigger: 1` → Skip triggers (silent operation, useful for bulk imports)
- **PITFALL**: Proposals **require** `{"notrigger": 0}` for validation. Orders and invoices work without it.

### Module Names Differ Between Operations
- **List/Upload/BuildDoc**: English names (`invoice`, `order`, `proposal`)
- **Download/Delete**: French names (`facture`, `commande`, `propal`)

### Date Fields
Dates are Unix timestamps in seconds (not milliseconds). Example: `"date": 1764288000`

### Contacts: `socid` vs `thirdparty_ids`
- **Creating contacts**: Use `socid` to link to a thirdparty (not `fk_soc`, which is silently ignored)
- **Filtering contacts by thirdparty**: Use `thirdparty_ids` parameter (not `socid` or `fk_soc`)

### `sqlfilters` Limitations
The `IN` operator is not supported. Fetch records individually or use multiple OR conditions.

### Line Endpoints: Singular vs Plural
- Proposals: `POST /proposals/{id}/line` (singular)
- Orders/Invoices: `POST /orders/{id}/lines` (plural)
- The MCP server handles this automatically.
