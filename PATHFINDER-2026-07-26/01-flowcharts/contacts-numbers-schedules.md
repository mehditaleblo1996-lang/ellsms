# Contacts, blacklist, numbers, number categories, schedules

```mermaid
flowchart TD
  subgraph contacts["contacts.php"]
    A1["POST do=add"] --> A2["normalize_msisdn() -> INSERT (no dedupe, no UNIQUE constraint)"]
    A3["POST do=import"] --> A4["ad-hoc line/CSV split, NOT parse_destinations() -> loop INSERT, no txn"]
    A5["POST do=delete"] --> A6["DELETE WHERE id=? AND user_id=? (ownership OK)"]
  end
  subgraph blacklist["blacklist.php"]
    B1["POST do=add"] --> B2["INSERT...ON DUPLICATE KEY (relies on UNIQUE(user_id,mobile))"]
    B3["POST do=bulk_add"] --> B4["own preg_split reimpl, NOT parse_destinations() -> INSERT IGNORE per line"]
  end
  subgraph numbers["numbers.php (admin)"]
    C1["do=assign"] --> C2["UPDATE assigned_user_id WHERE id=? *** no race guard on prior value ***"]
    C3["do=delete"] --> C4["DELETE WHERE id=? *** no in-use check ***"]
  end
  subgraph categories["number-categories.php (admin)"]
    D1["do=create"] --> D2["size-only validated .txt upload, ad-hoc split -> TRANSACTIONAL insert"]
    D3["do=delete"] --> D4["DELETE items then DELETE category *** NOT wrapped in a transaction (inconsistent with create) ***"]
  end
  subgraph schedules["schedules.php (list+cancel; CREATE lives in send.php)"]
    E1["do=cancel"] --> E2["UPDATE status='cancelled' WHERE id AND status IN(active,processing)<br/>*** races the worker's claim/finalize below ***"]
  end
  subgraph worker["app/backend.php: run_due_schedules() (background)"]
    W1["SELECT due rows"] --> W2["Claim: UPDATE status='processing' WHERE id=? AND status='active'"]
    W2 --> W3["dispatch_message() (network call, can take seconds)"]
    W3 --> W4["Final UPDATE status=done/active<br/>*** NO WHERE status='processing' guard -> can clobber user's 'cancelled' ***"]
  end
  E2 -. "lost-update race" .-> W4
```

External dependencies: `app/bootstrap.php` (parse_destinations — NOT reused by contacts/blacklist/number-categories's own importers), `app/backend.php` (run_due_schedules, dispatch_message).
