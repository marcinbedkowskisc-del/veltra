# VELTRA 2.0 — Stage 3E-B.3

## Status: PARTIAL

Lokalnie zaimplementowano dokumenty RAW, eksport CSV wybranych rekordów i kolumn
oraz walidację zbiorczej archiwizacji. **Nie zaimplementowano mutacji zbiorczej
archiwizacji — BLOCKED BY OWNER DECISION.** Zatwierdzone źródła nie rozstrzygają,
czy operacja ma być atomowa, czy dopuszczać częściowy sukces. Nie przeniesiono
polityki all-or-nothing z niezależnego kontraktu bulk sharing.

RAW_MATERIALS pozostaje **NOT_READY**. Nie wykonano aktywacji, wdrożenia,
commit ani push. Nie zmieniano instalacyjnych baz, kontenerów, wolumenów
ani poświadczeń. Bez frontendu, hard-delete B.2, Production i formal retirement.

## Źródła i zatwierdzenia

Przed implementacją odczytano bieżący kod i dokumenty:

- [100 decyzji UX](https://docs.google.com/document/d/1NECl8Hqq_auHzST6kJ-rovgSuMm30JhbxFWpx7ZIamM/edit),
  w szczególności decyzje 23–25, 38–41, 64, 95–98.
- [Uporządkowana specyfikacja](https://docs.google.com/document/d/1cQ_-fPrz5jXzrJvaaAxiXQGbjHqBvrMBOESbYKa0KQk/edit).
- [Raport zgodności](https://drive.google.com/file/d/1tUfpPa_zYgSe1j3C1UVe04pEFSDhJDfN/view).
- [Zaakceptowany B.1](https://drive.google.com/file/d/1kYq4Zj_Q6XFmUxGukPuAeqnS5kDfvuNd/view)
  i [B.2](https://drive.google.com/file/d/15BRCV9e3_MjNLZGv9nlfVdFk-bmrQxB5/view).

Właściciel zatwierdził zbiorczy plan lokalnych operacji B.3 oraz formaty:
**PDF, PNG, JPEG, TXT i CSV**. Nie zastępowało to natywnych zgód środowiska.

## Faktycznie wdrożone

### Upload i dokumenty rewizji

- Wieloplikowy staging w prywatnym prefiksie istniejącego magazynu.
  Niezależne wyniki STAGED/FAILED, opaque UUID, binding aktor/organizacja/lokalizacja.
- Rzeczywiste MIME przez finfo, zgodność rozszerzenia, bezpieczne nazwy,
  checksum i istniejący skaner. Limit 20 MiB na plik i łącznie plików
  w żądaniu; PHP POST 21 MiB uwzględnia narzut multipart.
- Trwałe metadane zapisuje się przed I/O. Klucz idempotencji jest związany
  z payloadem. Błąd jednego pliku nie usuwa udanych uploadów.
  Można ponowić multipart lub skan pojedynczego uploadu.
- Skan ERROR/INFECTED nie pozwala na claim ani pobranie.
  TTL wynosi godzinę, bez odnawiania. Cleanup działa co godzinę i używa
  FOR UPDATE SKIP LOCKED; usuwa wyłącznie wygasłe, nieprzypięte bajty.
  CLAIMED i pliki z trwałymi referencjami nie podlegają temu cleanupowi.
- Claim, dokument, wersja, link, immutable zestaw dokumentów, rewizja i audyt
  zapisują się w tej samej podpisanej transakcji B.2. Konflikt If-Match
  lub inny błąd zapisu wycofuje także częściowo wykonane claimy.
- Przy claim nie kopiuje się ani nie przenosi pliku: jego prywatny klucz
  staje się kluczem trwałej wersji. Eliminuje to rozjazd przeniesienia pliku
  i rollbacku SQL. Po commicie istniejący job ponownie skanuje wersję.
  Do aktualnego CLEAN dokument pozostaje zablokowany; potrzebny jest worker.
- Odpięcie zmienia zestaw nowej rewizji, nie usuwa dawnych linków ani plików.
  Zwykłe rewizje, korekty alergenów/storage i restore dziedziczą zestaw.
  Historyczne hashe V1/V2 pozostają bez zmian: załączniki nie są dodawane
  do ich treści; membership chronią immutable tabela i audyt rewizji.
- Odczyt używa obecnego show/editor, a nie osobnego szerszego zakresu.
  Odbiorcy mają wyłącznie obecną approved rewizję przy aktywnym share.
  Każde pobranie ponownie sprawdza widoczność, skan i checksum.
  PDF/PNG/JPEG mają preview; TXT/CSV tylko download.
  Nagłówki: bezpieczny Content-Disposition, CSP sandbox, nosniff, private/no-store.

### CSV

- Profil `selected_raw_catalog` rozszerza istniejący workflow; nie dodano
  drugiego silnika eksportów ani konfigurowalnych kolumn tabeli katalogowej.
- Wspólny query builder katalogu zachowuje filtry, sort i deterministyczność.
  Eksport obejmuje wszystkie wyniki albo wybrane ID ze wszystkich stron.
  Globalny ordinal liczy się przed ograniczeniem do wybranych ID.
- Whitelist kolumn, kolejność wybrana przez klienta; domyślnie pierwsze osiem
  pól katalogowych z pominięciem niedozwolonych pól finansowych.
  Ceny wymagają REFERENCE_PRICE_VIEW, koszt dodatkowo FOODCOST.
  Dostawcy i filtr dostawcy wymagają SUPPLIER_VIEW.
- Zachowano precyzję obliczeń B.1, ochronę przed formułami arkusza,
  prywatny manifest/hash, audyt, limity 5000 rekordów / 5 MiB
  i TTL 60 minut według istniejącej konfiguracji.
- Idempotency-Key wiąże żądanie z aktorem, organizacją i lokalizacją.
  Download ponawia wszystkie kontrole praw, filtrów i approved revision IDs.
  Cofnięty grant/share lub nowa approved rewizja blokuje starszy eksport.

### Bulk archive

Dodano wyłącznie walidację całej listy pod blokadami organizacji i materiałów:
istniejące ARCHIVE, primary kitchen, wspólne warunki pojedynczej archiwizacji
i wersje B.2. Brak wersji daje 428, konflikt 409. Audyt
`BULK_ARCHIVE_VALIDATED` ma wspólne operation_id i `mutation_performed=false`.

Odpowiedź zawiera `validated_count`, `mutation_available=false`
i `policy_status=BLOCKED_BY_OWNER_DECISION`. Walidacja nie rezerwuje stanu.
Przyszła mutacja musi ponownie autoryzować i walidować rekordy.
**Endpoint wykonawczy nie istnieje.**

## Kontrakty API

Wszystkie ścieżki poniżej mają prefiks `/api/v1/raw-materials` i istniejące
middleware sesji/identity/context, throttlingu oraz readiness/enablement.

| Metoda i ścieżka | Kontrakt |
|---|---|
| POST `/document-uploads` | Multipart `files[]`, `keys[]`; HTTP 200 z niezależnymi wynikami w `data`; przekroczenie sumy 413 przed stagingiem. |
| POST `/document-uploads/{upload}/retry` | Ponowienie skanu własnego, dostępnego uploadu. |
| Istniejące POST create i PATCH `/{material}/draft` | Dodatkowe `document_upload_ids: uuid[]`, `detach_document_version_ids: uuid[]`; zachowane SKU replay i If-Match. |
| GET `/{material}/documents` | `revision_id`, metadane i autoryzowane linki; opcjonalne `view=current\|editor`. |
| GET `/{material}/documents/{version}/download` | Pobranie po ponownej autoryzacji i sprawdzeniu integralności. |
| GET `/{material}/documents/{version}/preview` | Inline wyłącznie PDF/PNG/JPEG; brak gwarancji renderowania każdego PDF przez przeglądarkę. |
| POST `/catalog-exports/selection` | Idempotency-Key; opcjonalne `material_ids`, `columns`, istniejące filtry/sort; `page` i `per_page` zabronione. |
| GET `/catalog-exports/selection/{export}/download` | Ponownie autoryzowany prywatny CSV. |
| POST `/bulk-archive/validation` | `items: [{id, version}]`; tylko walidacja, bez zmiany lifecycle. |

Kolumny CSV: `ordinal`, `sku`, `name`, `unit`, `nominal_quantity`,
`usable_quantity`, `reference_net_price`, `usable_unit_cost`, `description`,
`category`, `lifecycle_state`, `yield_percent`, `supplier_names`.

## Zmienione pliki i migracja

Nowe:

1. [compose.stage3e-b3-test.yaml](./compose.stage3e-b3-test.yaml)
2. [Migracja 044](./backend/database/migrations/2026_11_09_000044_create_raw_document_uploads_and_revision_sets.php)
3. [RawMaterialUploadService.php](./backend/app/Domain/RawMaterials/Documents/RawMaterialUploadService.php)
4. [RawMaterialDocumentSetService.php](./backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentSetService.php)
5. [RawMaterialDocumentController.php](./backend/app/Http/Controllers/Api/V1/RawMaterialDocumentController.php)
6. [RawMaterialCatalogExportRequest.php](./backend/app/Http/Requests/RawMaterialCatalogExportRequest.php)
7. [uploads.ini](./backend/docker/uploads.ini)
8. [RawDocumentsStage3eB3Tests.php](./backend/tests/Concerns/RawDocumentsStage3eB3Tests.php)
9. [cleanup_raw_uploads.php](./backend/tests/Support/cleanup_raw_uploads.php)
10. Ten raport.

Zmodyfikowane:

1. [Dockerfile](./backend/Dockerfile)
2. [README.md](./backend/README.md)
3. [RawMaterialDocumentService.php](./backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentService.php)
4. [RawMaterialCsvCatalogExportWorkflow.php](./backend/app/Domain/RawMaterials/RawMaterialCsvCatalogExportWorkflow.php)
5. [RawMaterialService.php](./backend/app/Domain/RawMaterials/RawMaterialService.php)
6. [RawMaterialController.php](./backend/app/Http/Controllers/Api/V1/RawMaterialController.php)
7. [RawMaterialCsvCatalogExportController.php](./backend/app/Http/Controllers/Api/V1/RawMaterialCsvCatalogExportController.php)
8. [AuditSecurityRejection.php](./backend/app/Http/Middleware/AuditSecurityRejection.php)
9. [RawMaterialDataRequest.php](./backend/app/Http/Requests/RawMaterialDataRequest.php)
10. [bootstrap/app.php](./backend/bootstrap/app.php)
11. [routes/api.php](./backend/routes/api.php)
12. [routes/console.php](./backend/routes/console.php)
13. [RawEditingStage3eB2Tests.php](./backend/tests/Concerns/RawEditingStage3eB2Tests.php)
14. [RawMaterialsTest.php](./backend/tests/Feature/RawMaterialsTest.php)
15. [concurrent_raw_edit.php](./backend/tests/Support/concurrent_raw_edit.php)

044 jest jedyną migracją B.3, forward-only. Dodaje rejestr uploadów i immutable
zestawy dokumentów z FK, triggerami i podpisanym zakresem mutacji; rozszerza
wyłącznie CHECK storage_key wersji o prywatny prefiks uploadów.
Nie edytowano migracji 042–043. Runtime nie otrzymał DELETE tych tabel.
Migracje 001–044 wykonano wyłącznie na izolowanej bazie testowej.

## Weryfikacja

Projekt Compose: `veltra-stage3e-b3-test`, własna internal sieć i wolumeny,
bez published ports. PostgreSQL **17.11**; role `veltra_app` i
`veltra_migrator` bez superuser/createdb/createrole. Runtime nie ma CREATE
w public ani TRUNCATE organizations. Testy nie działały równolegle na jednej bazie.

| Kontrola | Wynik |
|---|---|
| Celowane B.1 + B.2 + B.3 + wcześniejszy race | **58 PASS, 635 asercji**, 114,57 s. |
| W tym nowe scenariusze B.3 | **25 PASS, 229 asercji** w obu przebiegach. |
| Pełna regresja backendu | **347 PASS, 3500 asercji**, 433,97 s; 0 błędów, porażek i pominięć. |
| Pint całego backendu | **285 plików PASS**. |
| git diff --check | **PASS**. |
| Konfiguracja PHP z uploads.ini | Potwierdzone 20M upload / 21M POST. |

Pokryto mixed upload, brakujący/nieprawidłowy plik, rzeczywiste MIME, nazwy
i limity, retry, actor/location/org isolation, TTL i cleanup, create replay,
rollback częściowego claimu, stale If-Match, detach/cancel i immutable historię,
PDF preview/TXT download, integralność i bezpieczne nagłówki, draft/pending/share
revocation, supplier evidence isolation, rzeczywistą współbieżność save/attach
oraz cleanup kontra niezatwierdzony jeszcze claim. Test restore sprawdza
dziedziczenie zestawu i odmowę pobrania starego eksportu po nowej akceptacji.

CSV: kolumny/filtry/sort, default bez finansów, niezależne granty ceny i FOODCOST,
precyzyjny koszt i odebranie grantu, hidden IDs/revoked share, formuły, hash,
idempotencja oraz faktyczny eksport **51 rekordów**, obejmujący zaznaczenie
z pierwszej i trzeciej strony. Bulk: bezmutacyjna walidacja, 428, stale/guessed ID
i pojedynczy audyt walidacji.

Testy stagingu kontrolują wyniki skanera przez mock istniejącego interfejsu
(CLEAN/ERROR/INFECTED); nie stanowią certyfikacji skuteczności antywirusowej.
Wcześniejsze przebiegi z błędami fixture nie były zaliczone jako PASS;
naprawiono je i powtórzono testy.

**Niewykonane celowo:** udana mutacja zbiorczej archiwizacji, jej race,
częściowy/atomowy sukces i audyt sukcesu mutacji — blokada decyzji właściciela.
Nie wykonano testów frontendowych ani wdrożenia.

## Zgodność, blokery i dostarczenie

Zachowano sześć ról, odrębne granty finansowe, ochronę oryginalnego SUPER ADMIN,
primary ownership, recipient isolation, explicit sharing/revoke, approved
visibility, append-only historię, K3 bulk sharing, B.1 i B.2 oraz bramki Stage 1E.
Nie nadano SUPER ADMIN automatycznego dostępu do finansów.

- Decyzja o atomowości bulk archive pozostaje jedynym blokerem wykonawczym B.3.
- Bloker fizycznego usuwania z B.2 pozostaje poza zakresem; bez obejścia.
- Przed użyciem po odrębnej zgodzie operacyjnej potrzebne będą migracja 044,
  worker skanera, scheduler cleanup i konfiguracja PHP; teraz niczego nie wdrożono.
- Końcowe logi i oba JUnit pozostają poza repo:
  `/home/veltra/.copilot/session-state/5dcc2b87-76d2-4e26-9865-f279b40e7535/files/`,
  pliki `stage3e-b3-focused.log/.xml`, `stage3e-b3-full.log/.xml`
  i `stage3e-b3-pint.log`. Liczniki potwierdzono również bezpośrednio w XML.
- Po zapisaniu JUnit usunięto wyłącznie dedykowany projekt testowy B.3:
  cztery kontenery, dwa wolumeny i sieć. Filtry etykiety projektu potwierdziły
  brak pozostałych kontenerów, sieci i wolumenów B.3.
- Google Drive: nie znaleziono wspieranego konektora uploadu. Nie ponawiano
  znanych niedostępnych ścieżek Windows/WSL ani nie obchodzono ograniczeń.
  Raport **nie został wysłany**; lokalna ścieżka:
  `/home/veltra/projekty/veltra/VELTRA_STAGE_3E_B3_RAW_DOCUMENTS_EXPORT_BULK_REPORT.md`.

**STOP po B.3.** Bez automatycznego przejścia do kolejnego pakietu.
