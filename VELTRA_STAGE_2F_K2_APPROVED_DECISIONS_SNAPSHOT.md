# VELTRA 2.0 — Stage 2F — zatwierdzone decyzje K2.1–K2.9

## Status i zakres

Niniejszy snapshot utrwala decyzje K2.1–K2.9 przekazane jako zatwierdzone przez właściciela projektu. Nie stanowi zgody na implementację. Dla zachowania dokładnego zakresu zatwierdzenia poniżej zamieszczono dosłowne brzmienie decyzji źródłowych; objaśnienia po polsku nie rozszerzają ich zakresu.

## Zatwierdzone decyzje — brzmienie źródłowe

### K2.1 — SUPER ADMIN model

SUPER ADMIN is NOT a seventh RBAC role.

It is a special status attached to an existing `ADMINISTRATOR` account.

The first account created through the trusted installation/bootstrap procedure receives this status automatically.

The status belongs to the primary central kitchen context, but organizational authorization must remain compatible with the existing RBAC architecture.

### K2.2 — Permanent identity

SUPER ADMIN status is permanently assigned to the original bootstrap user identity.

It cannot be transferred, reassigned or granted to another user.

Account recovery may restore access only to that same identity.

### K2.3 — Permission inheritance

SUPER ADMIN retains every standard permission of `ADMINISTRATOR`.

All other administrators retain their existing permissions except where a subsequently approved K2 decision explicitly restricts them.

SUPER ADMIN is not automatically the exclusive owner of financial data or price management.

Existing `FOODCOST_VIEW`, `PURCHASE_PRICES_VIEW` and RAW-specific financial permission separation must remain intact.

### K2.4 — Additional permission domains

Four additional areas are approved:

1. Critical administrative operations.
2. Advanced system configuration.
3. Extended oversight and audit.
4. Oversight of administrator permissions.

These are approved permission domains, NOT approval of every possible operation within those domains.

Do not invent additional privileged operations.

### K2.5 — Administrator account management

Only SUPER ADMIN may grant, revoke or modify permissions of accounts holding the `ADMINISTRATOR` role, including assigning or removing that role.

This is an explicit approved exception to the previous Stage 1D administrator model.

Normal administrators retain their other existing powers.

Existing rules for ordinary users and non-administrator roles must not be silently expanded or restricted.

### K2.6 — SUPER ADMIN account protection

SUPER ADMIN identity is permanent and protected.

The account cannot be deleted, deactivated, suspended, administratively blocked, stripped of its `ADMINISTRATOR` role or deprived of SUPER ADMIN status.

The prohibition also applies to actions initiated by SUPER ADMIN against their own account.

Do not create an administrative exception or a status-transfer procedure.

Normal authentication checks remain mandatory. Account recovery must preserve the same identity.

These rules do not authorize bypassing passwords, session checks, RBAC enforcement or module readiness restrictions.

### K2.7 — Administrator account management

Only SUPER ADMIN can manage other ADMINISTRATOR accounts, including roles, permissions, account status and location memberships.

Ordinary administrators retain their existing permissions concerning non-administrator accounts.

This intentionally modifies the previous Stage 1D RBAC rules.

### K2.8 — Primary central kitchen

The first central kitchen created in VELTRA automatically and permanently becomes the primary central kitchen.

No administrator, including SUPER ADMIN, can choose, change, reassign or transfer this designation.

The designation must follow actual creation order, not location codes, names or arbitrary UUID ordering.

For existing installations where the original creation order cannot be reliably established, do not guess or silently designate another kitchen.

This confirms K3.16 without changing it.

### K2.9 — SUPER ADMIN account editing

The SUPER ADMIN account is editable only by SUPER ADMIN themselves.

No other administrator may modify the account.

SUPER ADMIN may change their own email address and other editable profile details while preserving the same permanent identity.

Existing authentication and email verification requirements remain mandatory.

K2.6 continues to prohibit deletion, deactivation, suspension, administrative blocking, removal of the ADMINISTRATOR role or loss of SUPER ADMIN status, including self-initiated actions.

Do not invent exceptional recovery procedures.

## Wierne podsumowanie po polsku

- SUPER ADMIN jest nieprzenoszalnym statusem pierwotnej tożsamości bootstrapowej z rolą `ADMINISTRATOR`, a nie dodatkową rolą RBAC. Status jest związany z kontekstem głównej kuchni centralnej.
- Odzyskanie dostępu może dotyczyć wyłącznie tej samej tożsamości. Nie wolno przenieść statusu na inne konto.
- SUPER ADMIN zachowuje standardowe uprawnienia administratora. Pozostali administratorzy zachowują dotychczasowe uprawnienia z wyjątkiem jawnego ograniczenia K2.5.
- Uprawnienia finansowe pozostają oddzielone; status SUPER ADMIN sam w sobie nie przyznaje dostępu do danych finansowych ani cen.
- Zatwierdzono cztery domeny dodatkowych uprawnień, ale nie zatwierdzono konkretnych operacji w tych domenach. Ich lista wymaga osobnej decyzji; nie wolno jej dopowiadać.
- Wyłącznie SUPER ADMIN może zarządzać innymi kontami `ADMINISTRATOR`, w tym ich rolami, uprawnieniami, statusem konta i członkostwami lokalizacyjnymi. Zwykli administratorzy zachowują dotychczasowe uprawnienia wobec kont nieadministracyjnych. Jest to zamierzona zmiana poprzednich reguł Stage 1D.
- Konto i tożsamość SUPER ADMIN są chronione przed usunięciem, dezaktywacją, zawieszeniem, administracyjną blokadą, odebraniem roli administratora lub statusu. Ochrona obejmuje również działania własne. Nie tworzy się wyjątku ani procedury przeniesienia statusu.
- Pierwsza faktycznie utworzona kuchnia centralna staje się automatycznie i trwale główna. Żaden administrator nie może jej wybrać, zmienić, ponownie przypisać ani przekazać. Nie wolno ustalać kolejności według kodu, nazwy ani UUID. Dla istniejących instalacji o nieustalalnej kolejności nie wolno zgadywać ani po cichu wyznaczać innej centrali. K2.8 potwierdza K3.16 bez zmiany jego treści.
- Konto SUPER ADMIN może edytować wyłącznie sam SUPER ADMIN; inni administratorzy nie mogą go modyfikować. SUPER ADMIN może zmienić własny adres e-mail i inne edytowalne dane profilu, zachowując tę samą trwałą tożsamość i obowiązkową weryfikację e-mail. Zakazy K2.6 dotyczące usunięcia, dezaktywacji, zawieszenia, blokady, odebrania roli lub statusu pozostają bezwzględne, również dla działań własnych.
- Ochrona konta nie omija uwierzytelnienia, haseł, sesji, RBAC ani bramek gotowości modułów.

## Granica tego snapshotu

Zatwierdzono domeny uprawnień K2.4, lecz nie ich operacje. W szczególności ten dokument nie upoważnia do dodania dowolnego zarządzania sekretami, wdrożeniami, modułami, ustawieniami systemowymi ani do nadania SUPER ADMIN wyłącznego dostępu do finansów. K2.7–K2.9 określają wyłącznie zakres zarządzania kontami administratorów, niezmienność primary kitchen oraz edytowalne dane własnego konta SUPER ADMIN. Każda inna konkretna operacja w dodatkowych domenach wymaga osobnej, jawnej akceptacji właściciela.
