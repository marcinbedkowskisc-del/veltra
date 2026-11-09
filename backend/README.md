# VELTRA 2.0 API

Laravel REST API, part of a single-tenant modular monolith. Stage 1A infrastructure
health remains public. Stage 1B adds the installation organization, locations,
management history and independent supply eligibility. Stage 1C adds Laravel
Sanctum stateful SPA sessions, installation-wide user identities, historical
location memberships and a server-validated operating context.

The application service is `App\Domain\Installation\InstallationService`.
PostgreSQL enforces relational integrity, non-overlapping management periods,
history preservation and operational activation. Stage 1B management endpoints
still require the explicit local-development-only boundary, independently of any
session. This is not production authorization. `/api/v1/auth/*` uses first-party
cookie sessions and CSRF protection instead. Accounts and memberships grant no
business permissions by themselves. Stage 1D adds six scoped roles, hierarchical
account/role administration, historical grants, approval workflows and minimal
append-only security events under the independent `/api/v1/security` boundary.

Provision the initial identity with an interactive terminal:
`docker compose exec backend php artisan veltra:provision-initial-identity`.
An active installation organization must already exist. Passwords are hidden,
never command arguments. No default account, role or membership is created.
Subsequent identity provisioning is rejected. Next run the interactive
`docker compose exec backend php artisan veltra:bootstrap-authorization` command:
it establishes an initial context and pending administrator role, then sends an
activation email. The recipient must verify email and choose a new password
before logging in. Before activation, bootstrap safely supports rate-limited
resends; afterwards its one-way latch closes it permanently.

Local SMTP uses Mailpit at http://localhost:8025; no token is written to logs or
queued. Production requires real SMTP/TLS and HTTPS configuration, not this
development Compose. Reset initiation is restricted to authorized supervisors;
the recipient alone sets their password. Versioned identities invalidate old
sessions after password/email changes or deactivation.

`BusinessAuthorization` is deny-by-default and checks verified identity,
membership context, scoped roles and current kitchen-management relationships,
never supply relationships. `ModuleAvailability` and `ReportCatalog` are closed
integration contracts; no modules, business reports or exports are implemented.
PostgreSQL serialization/deferred constraints protect usable last-administrator
access and immutable security history. The legacy development API remains local,
explicitly enabled and token-gated; sessions do not replace its controls.

See the [project README](../README.md) for Docker setup and test commands.

## RAW — Stage 3E-B.2 (lokalny backend, nadal NOT_READY)

- `GET /api/v1/raw-materials/{material}/editor` zwraca bieżącą rewizję roboczą,
  oczekującą albo zatwierdzoną (w tej kolejności) i nieprzezroczyste `version`.
  Wymaga VIEW oraz dotychczasowego prawa wglądu w szkice (EDIT lub AUDIT_VIEW),
  wyłącznie w primary. Odbiorcy nie otrzymują roboczych danych ani tokena wersji.
  Kartoteka nadal prezentuje zatwierdzoną rewizję; nie zwraca tokena edycji,
  jeśli istnienie nowszej roboczej/oczekującej rewizji czyniłoby go mylącym.
- Wszystkie mutacje istniejącej rewizji RAW (`draft`, `draft/allergen-declaration`,
  `draft/storage-variants`) oraz `submit`, `approve`, `return`, `block`, `unblock`,
  `archive`, `restore` wymagają nagłówka `If-Match` z odczytanym UUID wersji
  (akceptowana też postać `"uuid"`). Brak: 428; błędny format: 422; stara wersja:
  409 `RAW_MATERIAL_VERSION_CONFLICT`, bez bieżących danych w odpowiedzi.
  Klienci legacy nie mogą pomijać tego warunku. Usługi domenowe wymagają
  analogicznego argumentu `expectedVersion`; domyślne NULL oznacza odmowę.
  Sprawdzenie następuje po ponownej autoryzacji, pod istniejącą blokadą organizacji
  i rekordu, w transakcji podpisanej mutacji. Migracja 043 generuje nowy UUID
  po każdym UPDATE materiału, także przy powrocie do wcześniejszego stanu.
  Tworzenie, ceny z własnym expected_event_no i atomowe udostępnianie zachowują
  odrębne kontrakty B.1/3D.1; nie są edycją rewizji RAW.
- `GET /{material}/completion`: stan, tożsamość rewizji, dozwolona wersja,
  `error_count`, `errors`, `missing_requirement_count`, `missing_requirements`,
  `eligible_for_next_action`, `available_actions`. Problemy mają stabilne
  `code`, `field`, `section`, polskie `message`. Warunki deklaracji alergenu
  i integralności są wspólne z zatwierdzaniem. Nie dodano obowiązkowych ilości,
  przechowywania, ceny ani dokumentu, których dotychczasowe zatwierdzanie
  nie wymagało. Braki zatwierdzania nie uniemożliwiają zapisu DRAFT ani istniejącego
  przekazania do weryfikacji. Nie ma procentu kompletności i autoaktywacji.
- `POST /{material}/validation/{section?}`: bez zapisu sprawdza niezapisane dane.
  Sekcje: `basic` (domyślna, body jak edycja danych podstawowych), `allergens`
  (body jak deklaracja), `storage` (body z `variants`). Wykorzystuje istniejące
  reguły i walidatory after oraz wspólne walidacje domenowe. Zwraca powyższy
  raport z błędami pól; preview nigdy nie udostępnia akcji mutacji. Wymaga
  właściwego grantu edycji sekcji i prawa odczytu szkicu.
- Akcje są informacyjne, wynikają ze stanu, efektywnych grantów (w tym grantów
  potrzebnych do zachowania istniejących danych rewizji), primary i bramek modułu.
  Mutacje ponownie sprawdzają własne warunki. Role administratora ani SUPER ADMIN
  nie tworzą tu automatycznych uprawnień.
- `GET /{material}/history` wymaga VIEW + AUDIT_VIEW; paginacja 25/50/100/200
  (domyślnie 50), kolejność czas malejąco, UUID malejąco. Zwraca ograniczoną
  projekcję istniejącego audytu: identyfikator zdarzenia, kod, czas i kanoniczne
  imię/nazwisko/UUID aktora, bez e-maila, surowych metadanych i payloadów.
  Primary widzi historię rewizji/lifecycle, udostępnień i powiązań dokumentów RAW;
  zdarzenia ceny referencyjnej wymagają osobnego grantu odczytu ceny.
  Odbiorca widzi wyłącznie zatwierdzenie obecnie udostępnionej rewizji.
  Nie jest to eksport wszystkich wewnętrznych logów komercyjnych/bezpieczeństwa.
- Kartoteka otrzymała `last_update` i dozwolone `history_url`, wyliczane z tej
  samej bezpiecznej projekcji audytu. Bez AUDIT_VIEW tożsamość aktora i link są
  ukryte. Zmiany ukrytych szkiców i cen nie zmieniają metadanych odbiorcy.
  Próba zapisu niezmienionej ceny jest w historii, lecz nie jako ostatnia zmiana.
- **Usuwanie szkiców: BLOCKED.** Istniejące bezwarunkowe triggery ochrony mastera,
  rewizji i dzieci rewizji oraz RESTRICT FK nie pozwalają bezpiecznie usunąć nawet
  nigdy niezatwierdzonego szkicu. Zgodnie z granicą B.2 nie osłabiono tych ochron
  i nie dodano DELETE endpointu ani akcji delete. SKU i audyt pozostają trwałe.
- Testy B.2: [oddzielny Compose](../compose.stage3e-b2-test.yaml), projekt
  `veltra-stage3e-b2-test`, dziedziczy wyłącznie konfigurację narzędziową B.1;
  ma własne kontenery, sieć, bazę i wolumeny. Migracje wyłącznie w izolacji.

## RAW — Stage 3E-B.1 (lokalny backend, nadal NOT_READY)

- `GET /api/v1/raw-materials`: wyszukiwanie fragmentu nazwy/SKU, filtry
  `category_id`, `supplier_id`, `lifecycle_state`, `unit`; domyślnie `name asc`,
  strony 25/50/100/200 (domyślnie 50). `ordinal` jest obliczane po globalnym
  sortowaniu. Archiwum nie jest częścią tego katalogu.
  Filtr dostawcy wymaga dodatkowo `RAW_MATERIALS_SUPPLIER_VIEW`.
- Ilości `nominal_quantity` i `usable_quantity` są dodatnimi tekstami dziesiętnymi
  (do 18 cyfr całkowitych i 6 ułamkowych), w `base_unit_code`; `pc` wymaga liczb
  całkowitych. Brak użytkowej przy podaniu nominalnej oznacza ich równość.
  Stare rewizje i niekompletne szkice mogą mieć obie wartości `null`.
  Zmiany tworzą nowe rewizje; `yield_percent` jest wyłącznie wynikiem obliczenia.
- Katalog i szczegóły zwracają `reference_net_price` w PLN za opakowanie tylko
  z `RAW_MATERIALS_REFERENCE_PRICE_VIEW`. `usable_unit_cost` wymaga dodatkowo
  skutecznego `FOODCOST_VIEW` w kontekście lokalizacji. Brak praw oznacza
  pominięcie pola, także zakaz sortowania po nim. Koszt ma bazę `PLN/100g`,
  `PLN/100ml` albo `PLN/pc`. PostgreSQL `numeric` i HALF_EVEN zachowują 14 miejsc;
  prezentacja dwóch miejsc należy do UI. Grant cen referencyjnych nie daje cen ofert.
- `POST /{material}/reference-prices` obsługuje alternatywnie
  `net_pln_per_package` lub dotychczasowe `net_pln_per_base_unit`.
  Cena opakowania wymaga zatwierdzonej ilości nominalnej; kwota jest zapisywana
  dokładnie, niezależnie od zaokrąglonego przeliczenia bazowego.
  Zapis i import ceny wymagają roli ADMINISTRATOR, właściwego grantu RAW
  oraz kontekstu primary. Powtórzenie niezmienionej ceny ręcznej tworzy audyt
  próby, ale nie nowe zdarzenie ceny. Dotychczasowe profile CSV pozostają bez zmian.
- Przed otwarciem nowego formularza klient wywołuje
  `POST /api/v1/raw-materials/sku-reservations` z pustym JSON i `Idempotency-Key`.
  Odpowiedź `201 data`: `reservation_id`, `sku`, `expires_at`, `status`.
  `POST /api/v1/raw-materials` konsumuje `sku_reservation_id` atomowo, bez
  kolejnej alokacji. Powtórzenie tego samego zapisu zwraca zapamiętaną odpowiedź;
  inna treść daje 409. Rezerwacja jest związana z aktorem, organizacją i lokalizacją,
  ważna 24 godziny. Po wygaśnięciu numer nie wraca do puli.
  Limity: 20 żądań/minutę na aktora, 100 nowych rezerwacji/dobę na aktora
  i 1000 na organizację. Ponowienie klucza nie tworzy nowej rezerwacji.
  Starsze tworzenie bez rezerwacji pozostaje zgodne wstecznie.
- Nowe RAW mają wyłącznie sześć cyfr, maksymalnie `RAW-999999`; wyczerpanie daje
  409. Historyczne przypisania nie są przepisywane. INT/PRD/MEN zachowują
  poprzedni zakres. Unikalność pełnego RAW jest dodatkowo wymuszona w instalacji.
- Migracja 042 jest forward-only. Do testów służy wyłącznie
  [izolowany Compose 3E-B1](../compose.stage3e-b1-test.yaml), z syntetycznym
  plikiem `--env-file`; PostgreSQL 17, Redis, prywatna sieć i wolumeny mają osobny
  prefiks projektu. Runner wykorzystuje istniejący obraz narzędziowy testów 3D.1,
  montując aktualne źródła. Nie uruchamiać migracji na bazie instalacyjnej.
