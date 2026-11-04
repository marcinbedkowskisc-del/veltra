# VELTRA 2.0 — Stage 2C: końcowa remediacja F-2

## Podsumowanie i status

Wprowadzono wąskie zabezpieczenia dla danych dostawców i liczników kodów RAW_MATERIALS w nowej, forward-only migracji 026. Istniejące migracje 001–025 pozostały bez zmian. Nie zmieniono logiki Laravel ani architektury RBAC/HMAC.

**Nie deklaruję ACCEPT dla Stage 2C.** Wykonano wyłącznie statyczny przegląd granicy draft/approval oraz nieadwersarialne testy funkcjonalne. Niezależny runtime re-audyt bezpieczeństwa nadal pozostaje do wykonania, a pełna suita backendu i testy adwersarialne nie zostały uruchomione. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne.

## Zmiany

### Dane dostawców

Nowa migracja [2026_11_06_000026_protect_supplier_identity_and_raw_material_code_counters.php](./backend/database/migrations/2026_11_06_000026_protect_supplier_identity_and_raw_material_code_counters.php) zastępuje funkcję ochronną dostawcy. Po utworzeniu nie można zmienić `id`, `organization_id`, `supplier_code`, `display_name`, `legal_name`, `tax_identifier_ciphertext`, `created_by` ani `created_at`. `updated_at` może zmienić się wyłącznie przy przejściu `ACTIVE → ARCHIVED`; ponowne odarchiwizowanie jest blokowane.

W aktualnym kodzie nie ma obsługiwanej ścieżki edycji danych dostawcy. Istniejące tworzenie i archiwizacja przez `RawMaterialCommercialService` pozostają bez zmian. Test funkcjonalny potwierdza, że archiwizacja nie zmienia danych dostawcy i nadal zapisuje zdarzenie audytowe.

Kontrola ta nie czyni z bazy kompletnej warstwy autoryzacji biznesowej. Migracja nie wiąże bezpośrednio wstawienia dostawcy ani przejścia do `ARCHIVED` z rekordem audytu; takie operacje nadal zależą od wspieranej ścieżki Laravel. Nie próbowano ich omijać bezpośrednim SQL.

### Liczniki kodów

Migracja 026:

- odmawia zastosowania, jeżeli istniejące kody/liczniki są niespójne; nie naprawia ani nie przepisuje historii;
- blokuje usunięcie licznika, zmianę jego klucza oraz regresję lub skok większy niż jeden;
- wymaga, aby licznik zaczynał od zera, a wstawiany kod `SUR-...` odpowiadał aktualnie alokowanej wartości;
- dodaje odroczoną kontrolę zgodności alokacji z licznikiem na końcu transakcji;
- ogranicza `EXECUTE` do nowych funkcji triggerów.

Dotychczasowa alokacja w `RawMaterialService::create()` nadal blokuje organizację/licznik, inkrementuje licznik i tworzy rekord w tej samej transakcji. Testowy helper syntetycznych draftów aktualizuje teraz licznik zgodnie z sekwencją, a test poprawnej alokacji wymusza sprawdzenie odroczonych triggerów.

### Draft i approval

Statycznie sprawdzono istniejącą granicę: rewizje są append-only, jednostki i aliasy robocze odnoszą się do aktualnej rewizji roboczej, a `approve()` ponownie oblicza hash treści i zapisuje zdarzenie approval przed zmianą rewizji autorytatywnej. Zdarzenie approval podlega istniejącej ochronie mutacji podpisanych. Nie znaleziono w tym przeglądzie źródła luki wymagającej rozszerzenia remediacji.

To jest ocena kodu oraz wynik dozwolonych testów funkcjonalnych, a nie dowód odporności na bezpośredni SQL. Nie zmieniano tabel rewizji, jednostek ani aliasów.

## Weryfikacja w izolowanym środowisku

Użyto osobnej sieci Docker `veltra-f2-remediation-test` z opcją `internal`, dedykowanego PostgreSQL 17.11, Redis 7.4.12 oraz tymczasowego runnera PHP 8.4.27. PostgreSQL miał wyłącznie testowe dane na `tmpfs`; Redis również nie używał wolumenu trwałego. Nie wystawiono portów hosta. Kod źródłowy był podmontowany tylko do odczytu, a miejsca zapisu runnera znajdowały się na `tmpfs`.

Runner łączył się z bazami `veltra_testing` i `veltra_upgrade_final` na tej sieci. Użyto wyłącznie syntetycznych haseł i kluczy; nie odczytywano ani nie kopiowano sekretów aplikacji. Testowe procesy Laravel używały roli migracyjnej `veltra_migrator`; jej przetestowane flagi PostgreSQL to `NOSUPERUSER`, `NOCREATEDB`, `NOCREATEROLE`, `NOBYPASSRLS`. Nie jest to weryfikacja operacji aplikacji z ograniczoną rolą runtime `veltra_app`.

Sześć istniejących kontenerów aplikacji pozostało uruchomionych; pięć z raportowanym healthcheckiem miało stan `healthy`, a kolejka działała bez healthchecka. Nie podłączano ich do testowej sieci ani nie modyfikowano ich, istniejących baz lub wolumenów. Nie utworzono ani nie zmieniono pliku `.env`.

## Wyniki

| Kontrola | Wynik |
|---|---|
| Świeże migracje 001–026 na PostgreSQL 17.11 | PASS |
| Upgrade od stanu migracji 025 do 026 | PASS |
| Fail-closed dla niespójnego syntetycznego licznika | PASS; brak wpisu 026 i brak częściowych funkcji/triggerów |
| Zachowanie danych syntetycznych po odrzuceniu migracji | PASS; organizacja i wiersz licznika pozostały bez zmian |
| Upgrade po korekcie wyłącznie syntetycznego licznika | PASS; organizacja i poprawiony licznik zachowane |
| Testy funkcjonalne RAW_MATERIALS, supplierów i ofert | 8 PASS, 104 asercje |
| Współbieżna alokacja kodów i audyt | 1 PASS, 9 asercji |
| Testy jednostkowe backendu | 4 PASS, 39 asercji |
| Łączny końcowy zestaw uruchomiony | **13 PASS, 152 asercje, 0 failed, 0 PHPUnit skipped** |
| PHP lint i Pint zmienionych plików | PASS |
| `sh -n docker/postgres/10-create-runtime-role.sh` | PASS |
| `docker compose --env-file /dev/null config --quiet` z wartościami testowymi | PASS |
| `git diff --check` | PASS |

W przypadku niespójnej historii test celowo wstawił w bazie disposable organizację i licznik `1` bez odpowiadającego kodu. Migracja 026 zgłosiła precondition i nie zapisała wersji migracji ani DDL. Zweryfikowano zachowanie wiersza. Po ustawieniu licznika na `0` w tej samej bazie syntetycznej migracja zastosowała się poprawnie. Nie była to baza aplikacji.

Pierwsze uruchomienie jednego testu workflow draftu zakończyło się błędem 401, ponieważ runner nie miał testowej wartości `SANCTUM_STATEFUL_DOMAINS`. Po dodaniu tej konfiguracji wyłącznie do procesu testowego test przeszedł; końcowy przebieg obejmujący ten test ma wynik PASS. Nie stwierdzono defektu aplikacji wynikającego z tej pierwszej próby.

Nie uruchomiono pełnej suity backendu. Zgodnie z ograniczeniem nie uruchamiano scenariuszy SQL adwersarialnych ani testów atakujących granicę autoryzacji. Nie sprawdzono bezpośrednim SQL odrzucenia zmiany danych dostawcy ani regresji licznika przy rzeczywistej roli runtime. Testy PASS dotyczą wyłącznie jawnie wymienionego zakresu.

## Status pozostałych tabel F-2

| Tabela | Zmiana / wynik | Status i brakujące dowody |
|---|---|---|
| `organization_suppliers` | Zamrożono pola tożsamości i biznesowe po utworzeniu; testowano poprawne tworzenie i archiwizację Laravel. | Kontrola kodowa dodana. Brak niezależnego testu runtime odrzucenia SQL; brak DB-owego powiązania wstawienia/archiwizacji z audytem pozostaje ryzykiem do oceny właściciela. |
| `raw_material_code_counters` | Monotoniczność, alokacja dopasowana do kodu i odroczona spójność. Współbieżna ścieżka aplikacji przeszła. | Kontrola kodowa i pozytywny workflow zweryfikowane. Bezpośrednie próby regresji/korupcji z rolą runtime nie wykonano. |
| `raw_material_categories` | Bez zmian; istniejące kontrole ograniczają strukturę/hierarchię. | Ryzyko bezpośrednich zmian kategorii bez aplikacyjnej autoryzacji/audytu pozostaje bez decyzji właściciela. |
| `raw_material_revisions` | Bez zmian; sprawdzono statycznie append-only, hash i ścieżkę approval. | Testy funkcjonalne draft/approval przeszły. Możliwość bezpośredniej modyfikacji draftu i jej wpływ wymagają osobnej dozwolonej weryfikacji; nie uznano ryzyka za zaakceptowane. |
| `raw_material_revision_units` | Bez zmian; elementy draftu są powiązane z rewizją roboczą. | Dozwolony workflow przechodzi; bezpośrednie SQL do draftu nie testowano, a ryzyko nie ma zgody właściciela. |
| `raw_material_aliases` | Bez zmian; aliasy pozostają powiązane z rewizją roboczą. | Dozwolony workflow przechodzi; bezpośrednie SQL do draftu nie testowano, a ryzyko nie ma zgody właściciela. |

## Pozostałe warunki

1. Niezależny, dozwolony runtime re-audyt bezpieczeństwa aktualnej migracji 026 pozostaje otwarty. Nie wolno obchodzić wcześniejszych blokad usługi.
2. Pełny backend regression oraz testy poza wybranym nieadwersarialnym zakresem nie są zaliczone przez ten raport.
3. Właściciel projektu nie wydał jawnej decyzji dla pozostałych ryzyk F-2. Ten raport nie akceptuje ich w jego imieniu.
4. Weryfikacja syntetycznego upgrade’u nie mówi nic o historii, rolach, grantach ani stanie istniejącej instalacji. Przed jakimkolwiek wdrożeniem wymagany jest osobny przegląd konkretnej instalacji oraz jej danych.
5. Migracja 026 jest forward-only i odrzuca niespójne zastane liczniki/kody bez automatycznej naprawy. Każda taka historia wymaga osobnego przeglądu i decyzji przed migracją.

**Wniosek:** remediacja kodu dla pól dostawcy i alokacji licznika została wprowadzona i przeszła wymienione testy funkcjonalne/migracyjne. To nie zamyka całego F-2 ani Stage 2C i nie stanowi niezależnej akceptacji bezpieczeństwa.
