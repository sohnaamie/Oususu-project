# Osusu API

A Laravel REST API for managing osusu (rotating savings) groups. Built for any type of osusu: weekly or monthly, rotating payout or lump sum at the end (like a Ramadan package).

## Features
- Token authentication (Laravel Sanctum)
- Groups with configurable amount, frequency, length and payout type
- Roles per group: admin and member (the same person can be admin in one group and member in another)
- Random turn order for rotating groups, with late joiners supported
- Payments stored as an append-only history, balances calculated (never stored)
- Corrections instead of edits or deletes, so the audit trail is kept
- Policies: outsiders get 404, plain members get 403

## Tech
Laravel ______, PHP ______, SQLite (dev)

## Run locally
```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
php artisan test
```

## Endpoints (all under /api/v1, JSON, send `Accept: application/json`)

| Method | URL | Who | Purpose |
|---|---|---|---|
| POST | /register | anyone | create account, returns token |
| POST | /login | anyone | returns token |
| GET | /groups | logged in | your groups |
| POST | /groups | logged in | create group (you become admin) |
| GET | /groups/{id} | member | group details |
| GET | /groups/{id}/members | member | member list |
| POST | /groups/{id}/members | ______ | add a member by email |
| POST | /groups/{id}/start | ______ | start group, assign turns |
| POST | /groups/{id}/contributions | ______ | record a payment |
| POST | /groups/{id}/contributions/{cid}/corrections | ______ | reverse a payment |
| GET | /groups/{id}/balances | member | paid, reversed, balance, expected |

## Design decisions
- Money is stored as integers in butut (D50 = 5000)
-  Balances calculated, not stored: two copies of the same number can disagree, and then nobody knows which is right.
- Corrections as a separate table: keeps the payment history clean and every mistake visible.
- Constraints and locks, not only PHP checks: PHP can’t see another request happening at the same moment, but the database can.

## Known limitations
- Members with multiple shares pay per share but currently receive one payout turn. One turn per share is planned.
- Late joiners owe all past periods (catch-up payments).
- No payment reminders yet.

## Example request
http://127.0.0.1:8000/api/v1/groups/6/members
[
    {
        "id": 12,
        "group_id": 6,
        "user_id": 1,
        "role": "admin",
        "turn_order": 1,
        "status": "active",
        "number_of_shares": 1,
        "joined_at": null,
        "created_at": "2026-10-31T12:31:45.000000Z",
        "updated_at": "2026-10-31T12:45:37.000000Z",
        "user": {
            "id": 1,
            "name": "Yassin"
        }
    },
    {
        "id": 13,
        "group_id": 6,
        "user_id": 2,
        "role": "member",
        "turn_order": 2,
        "status": "active",
        "number_of_shares": 1,
        "joined_at": "2026-10-31 12:41:56",
        "created_at": "2026-10-31T12:41:56.000000Z",
        "updated_at": "2026-10-31T12:45:37.000000Z",
        "user": {
            "id": 2,
            "name": "Matarr"
        }
    },
    {
        "id": 14,
        "group_id": 6,
        "user_id": 3,
        "role": "member",
        "turn_order": 3,
        "status": "active",
        "number_of_shares": 2,
        "joined_at": "2026-10-31 12:43:53",
        "created_at": "2026-10-31T12:43:53.000000Z",
        "updated_at": "2026-10-31T12:45:37.000000Z",
        "user": {
            "id": 3,
            "name": "Aminata"
        }
    },
    {
        "id": 15,
        "group_id": 6,
        "user_id": 5,
        "role": "member",
        "turn_order": 4,
        "status": "active",
        "number_of_shares": 2,
        "joined_at": "2026-10-31 12:57:30",
        "created_at": "2026-10-31T12:57:30.000000Z",
        "updated_at": "2026-10-31T12:57:30.000000Z",
        "user": {
            "id": 5,
            "name": "Ida"
        }
    }
]