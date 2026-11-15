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

## RAW — pricing and nominal/usable quantity contract

This contract supersedes older stage notes below for quantity and pricing
behavior.

Each newly created RAW material requires a nominal package quantity. The usable
quantity is optional: omission means it equals the nominal quantity, while an
explicit usable quantity is retained as a distinct yield value. If nominal
quantity changes while usable quantity is omitted, the new usable quantity
defaults to the new nominal value.

Reference price input is manual and supports either a net package price or a net
price per nominal kg, liter or piece. Both are normalized to the same package
price; supplier associations remain informational and do not determine price.
Price input and nominal/usable quantity provenance are included in revision
hash version 3, while existing V1/V2 revision hashes remain verifiable.
Approved price history records the input basis and both quantity snapshots.
Usable costs use net package price divided by usable quantity and are presented
per 100 g, per 100 ml or per piece. CSV pricing uses the same normalization.
Price visibility and editing continue to require their existing effective RAW
permissions; this contract does not change module readiness or grants.

## RAW — Stage 3E-B.3 (lokalny backend, PARTIAL, nadal NOT_READY)

- `POST /api/v1/raw-materials/document-uploads`: multipart `files[]` oraz
  odpowiadające im unikalne `keys[]` (1–120 znaków `A-Za-z0-9._:-`).
  Odpowiedź HTTP 200 zawiera niezależne wyniki `STAGED` / `FAILED` w `data`.
  Poprawny wynik zawiera nieprzezroczyste `upload_id`, metadane i `expires_at`;
  wynik błędu zawiera polski komunikat i `error_code`. Nie zwraca ścieżek dysku.
  PDF, PNG, JPEG, TXT i CSV wymagają zgodnego rzeczywistego MIME i rozszerzenia.
  Obowiązuje 20 MiB na plik i łącznie plików w żądaniu; przekroczenie sumy
  odrzuca całe żądanie przed stagingiem. Kolejne żądania umożliwiają dodanie
  kolejnych załączników. Limit PHP POST wynosi 21 MiB z narzutem multipart.
- Upload wymaga istniejącego CREATE lub EDIT i jest związany z aktorem,
  organizacją i lokalizacją. Nie ma publicznego odczytu stagingu. Ten sam klucz
  i plik zwracają ten sam upload; inny payload oznacza konflikt.
  `POST /api/v1/raw-materials/document-uploads/{upload}/retry` ponawia skan
  dostępnego pliku; ponowienie multipart pozwala ponowić nieudany zapis pliku.
  Błędy skanera i wykryte zagrożenia nie pozwalają na przypięcie.
- Staging wygasa po godzinie, bez przedłużania przy ponowieniu. Polecenie
  `php artisan raw-materials:cleanup-uploads` jest planowane co godzinę.
  Usuwa wyłącznie wygasłe, nieprzypięte pliki pod blokadą `SKIP LOCKED`,
  nigdy historię lub pliki mające trwałe referencje. Metadane pozostają.
- Istniejące create oraz zapis draft przyjmują `document_upload_ids: uuid[]`
  i `detach_document_version_ids: uuid[]`. Claim, dokument, wersja, link,
  niezmienny zestaw dokumentów, rewizja i audyt mają wspólną transakcję.
  Nadal obowiązuje B.2 `If-Match`; konflikt lub nieudany zapis wycofuje claim.
  Powtórzenie udanego create z rezerwacją SKU nie dodaje drugiego dokumentu.
  Anulowanie odpięcia oznacza pominięcie ID przy zapisie; nie ma osobnego DELETE.
- Odpięcie zmienia wyłącznie zestaw nowej rewizji, bez usuwania dawnych linków
  i bajtów. Nowe rewizje, korekty alergenów/storage i restore dziedziczą zestaw.
  Historyczne hashe V1/V2 pozostają bez zmian: załączniki nadal są poza ich
  treścią, a membership utrwalają niezmienna tabela i audyt.
  Po commicie istniejący job ponownie skanuje trwałą wersję; do aktualnego
  wyniku CLEAN dokument pozostaje niedostępny.
- `GET /api/v1/raw-materials/{material}/documents` zwraca dokumenty bieżącej
  widocznej rewizji. Opcjonalne `view=editor` używa istniejącego kontraktu
  edytora (VIEW i EDIT albo AUDIT_VIEW w kuchni głównej).
  Linki `/{material}/documents/{version}/download` oraz `/preview` ponownie
  sprawdzają zakres, rewizję, skan i checksum. Odbiorca widzi wyłącznie obecną
  zatwierdzoną rewizję przy aktywnym udostępnieniu, nigdy draft/pending.
  PDF/PNG/JPEG można wyświetlić inline; TXT/CSV tylko pobrać.
  Odpowiedzi mają bezpieczny Content-Disposition, CSP sandbox, nosniff i
  private/no-store. API nie gwarantuje renderowania każdego PDF przez przeglądarkę.
- `POST /api/v1/raw-materials/catalog-exports/selection` wymaga nagłówka
  `Idempotency-Key`. Opcjonalne `material_ids` wybiera rekordy ze wszystkich
  stron; brak pola eksportuje wszystkie pasujące rekordy. `columns` wybiera
  pola w podanej kolejności: `ordinal`, `sku`, `name`, `unit`,
  `nominal_quantity`, `usable_quantity`, `reference_net_price`,
  `usable_unit_cost`, `description`, `category`, `lifecycle_state`,
  `yield_percent`, `supplier_names`. Domyślne są pierwsze osiem z pominięciem
  niedozwolonych pól finansowych. Nie zmienia to stałych kolumn katalogu.
  Filtry i sortowanie są wspólne z katalogiem; `page`/`per_page` są zabronione.
  `ordinal` zachowuje pozycję w całym przefiltrowanym katalogu przed wyborem ID.
- Wybrany eksport korzysta z istniejącego workflow CSV, limitów (domyślnie
  5000 rekordów / 5 MiB), manifestu, hasha, audytu i TTL (domyślnie 60 minut).
  Wartości grożące wykonaniem formuły odrzucają eksport wybranych pól.
  VIEW nie daje cen; cena wymaga REFERENCE_PRICE_VIEW, koszt dodatkowo FOODCOST.
  `supplier_names` i filtr dostawcy wymagają SUPPLIER_VIEW.
  `GET /api/v1/raw-materials/catalog-exports/selection/{export}/download`
  ponownie sprawdza wszystkie uprawnienia, udostępnienia, filtry i aktualne
  zatwierdzone rewizje. Cofnięcie dostępu lub nowa zatwierdzona rewizja blokuje
  pobranie starszego eksportu.
- `POST /api/v1/raw-materials/bulk-archive/validation` przyjmuje
  `items: [{id, version}]`. Wymaga dotychczasowego ARCHIVE, kuchni głównej
  i warunków archiwizacji pojedynczego materiału; sprawdza wszystkie rekordy
  pod blokadami i zachowuje B.2 (brak wersji 428, konflikt 409). Zwraca
  `mutation_available: true`, `mutation_performed: false`,
  `policy_status: ALL_OR_NOTHING` i `validated_count`. Walidacja nie rezerwuje
  stanu; endpoint mutacji ponownie autoryzuje i sprawdza całą listę.
- `POST /api/v1/raw-materials/bulk-archive` przyjmuje te same
  `items: [{id, version}]` oraz wymagany `reason`; limit wynosi 100 materiałów.
  W ramach jednej podpisanej transakcji blokuje organizację, następnie wszystkie
  materiały w kolejności UUID, sprawdza uprawnienie ARCHIVE, wersje i warunki
  lifecycle przed pierwszą zmianą, po czym zapisuje stan ARCHIVED i kanoniczny
  audyt `ARCHIVED` dla każdej pozycji. Błąd dowolnej pozycji lub zapisu wycofuje
  całość. Powtórzenie nie tworzy kolejnych zdarzeń: stare wersje powodują
  konflikt, a aktualna wersja zarchiwizowanego materiału nie spełnia lifecycle.
  Historia rewizji, dokumentów i relacji handlowych pozostaje bez zmian.
- Migracja 044 jest forward-only. Testy korzystają wyłącznie z
  [izolowanego Compose B.3](../compose.stage3e-b3-test.yaml), PostgreSQL 17,
  ograniczonej roli runtime i osobnych zasobów. Bez migracji instalacji,
  aktywacji modułu, frontendu, hard-delete i zmiany K3 bulk sharing.

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
- **Stage 3E-B.4.1 — nieodwracalny tombstone szkicu:** `DELETE
  /api/v1/raw-materials/{material}/draft` wymaga bieżącego `If-Match`,
  niedelegowalnego `RAW_MATERIALS_DELETE_DRAFT` oraz primary central kitchen.
  Akceptuje wyłącznie `DRAFT` bez historii `APPROVED`/`RETURNED` i bez trwałych
  zależności (m.in. dostawcy, cen, importu, udostępnień, ocen zgodności
  i przypiętych dokumentów). Puste zestawy dokumentów i nieprzypięte uploady
  stagingowe nie blokują operacji.
- Operacja zachowuje master, wszystkie rewizje, audyt, przypisanie SKU i kod;
  rekord staje się niezmiennym tombstone’em, jest ukrywany w zwykłych odczytach
  i nie można ponownie użyć jego tożsamości ani SKU. To nie jest archiwizacja
  ani formalne wycofanie. Fizyczny DELETE, aktualizowanie rewizji i globalne
  wyłączanie triggerów/FK pozostają zabronione.
- Migracja 045 jest forward-only. Weryfikacja B.4.1 korzysta z osobnego,
  nietrwałego projektu [izolowanego Compose](../compose.stage3e-b41-test.yaml);
  nie uruchamiać jej na bazie instalacyjnej. `RAW_MATERIALS` pozostaje
  `NOT_READY`; backendowa implementacja nie oznacza wdrożenia ani aktywacji.
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
- Katalogowy CSV `POST /api/v1/raw-materials/catalog-imports` używa nagłówka
  `sku;entity_type;name;description;base_unit_code;category_code;nominal_quantity;usable_quantity`.
  Ilość nominalna jest wymagana; pusta ilość użytkowa domyślnie równa się nominalnej,
  a jawna zachowuje pochodzenie. Starszy nagłówek sześciokolumnowy jest rozpoznawany,
  ale podgląd blokuje jego wiersze jako niekompletne. Historia importu zachowuje
  obie ilości oraz informację, czy użytkowa była podana jawnie. Import cen
  referencyjnych pozostaje bez zmian.
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
  [izolowany Compose 3E-B1](../compose.stage3e-b1-test.yaml), z jawnym
  syntetycznym plikiem `--env-file` i projektem `veltra-test-stage3e-b1`.
  PostgreSQL/Redis używają `tmpfs`, sieć jest wewnętrzna, a niejawne wolumeny
  instalacji nie są podłączane. Korzystaj z `scripts/compose-test-clean` do
  weryfikacji; rzeczywiste sprzątanie wymaga odrębnego rootless Docker daemon.
  Nie uruchamiać migracji na bazie instalacyjnej.
