# VELTRA 2.0 — Stage 2F — Implementation 3C
## Raport preflight: historyczna rewizja RAW i cofnięcie udostępnienia

- **Data:** 2026-11-09
- **Wynik:** **PARTIAL / BLOCKED**
- **Zakres wykonany:** obowiązkowy preflight istniejącego workflow; bez zmian kodu i schematu.
- **RAW_MATERIALS:** pozostaje `NOT_READY`.

## Decyzja preflight

W repozytorium nie ma zatwierdzonego przepływu produkcyjnego, partii ani zużycia,
który rejestrowałby rozpoczęcie operacji i używaną rewizję RAW. Zgodnie z bramką
3C nie dodano nowej domeny produkcyjnej ani tabeli bez konsumenta. Integracja
produkcji i pełna akceptacja 3C pozostają **BLOCKED** do czasu dostarczenia
istniejącego zatwierdzonego workflow oraz jego kontraktu.

## Ustalenia wymagane przed zmianami

1. **Rzeczywiste wejścia użycia RAW:** trasy i usługi obsługują katalog,
   rewizje, ich zatwierdzanie, udostępnienia, handel i import/eksport CSV.
   `RawMaterialLocationSharePolicy::assertCanConsume` jest używana przy
   odczytach katalogowych/importach; nie znaleziono endpointu rozpoczęcia
   produkcji ani zużycia. Importowe „batch” to wyłącznie historia importu CSV.
2. **Trwały zapis trwającej operacji:** istnieją tabele materiałów, rewizji,
   zatwierdzeń, udziałów oraz batchy importu CSV. Nie znaleziono modelu ani
   tabeli operacji produkcyjnej/zużycia, stanu in-progress, receptury lub partii
   produkcyjnej.
3. **Niezmienny odnośnik rewizji:** `raw_materials.approved_revision_id`
   wskazuje bieżącą zatwierdzoną rewizję. Historia rewizji i zatwierdzeń istnieje,
   ale żaden rekord operacji nie zapisuje odnośnika do rewizji, materiału,
   organizacji i lokalizacji jako zamrożonego kontekstu.
4. **Moment autoryzacji i wyboru rewizji:** obecny odczyt odbiorcy sprawdza
   aktywny udział i aktualne zatwierdzenie; `RawMaterialService::show` wybiera
   rewizję przez bieżący `approved_revision_id`. Nie ma momentu „startu
   operacji”, w którym można atomowo autoryzować użycie i utrwalić rewizję.
5. **Cofnięcie udziału:** 3A zapisuje `revoked_at`, administratora, kontekst,
   operation ID i audyt; nie usuwa rekordu. Polityka odrzuca późniejsze nowe
   odczyty bez aktywnego share. Nie istnieje operacja kontynuacji ani
   zakończenia produkcji, którą można odróżnić od nowego użycia.

## Współbieżność i granice bezpieczeństwa

Istniejące mutacje share/revoke oraz zatwierdzenie rewizji blokują wiersz
organizacji w transakcji, a zatwierdzenie zmienia wskaźnik rewizji wraz z audytem.
Odczytowa kontrola `canConsume` nie utrwala operacji ani rewizji i nie ustanawia
punktu serializacji dla startu produkcji. Zatem nie można dowieść, że równoległy
start nie zostanie autoryzowany po zatwierdzonym revoke, ani że operacja
zachowa V1 po zatwierdzeniu V2. Testy współbieżności dla istniejących mutacji
share/approval nie zastępują testu startu operacji.

K3.21 rozróżnia zwykłe cofnięcie od interwencji bezpieczeństwa żywności.
Lokalny projekt K3 wskazuje, że właściciel integracji musi określić źródło
blokady bezpieczeństwa i tryb hold już rozpoczętej partii. Brak tego kontraktu
jest osobną decyzją właściciela. Nie wprowadzono automatycznej zgody na
kontynuację, recall, quarantine, HACCP release, override ani procedury
bezpieczeństwa.

## Zakres zmian i weryfikacja

- **Zmodyfikowane pliki aplikacji:** brak.
- **Nowe migracje:** brak; migracji nie uruchamiano.
- **Nowe testy / testy wykonane:** 0 / 0; **asercje:** 0. Nie uruchomiono testów
  PHP ani PostgreSQL, ponieważ preflight nie znalazł zatwierdzonego konsumenta,
  a instrukcja wymaga zatrzymania przed wymyślaniem workflow.
- **PHP lint / Pint:** nie dotyczy — zmieniono wyłącznie raport Markdown.
- **`git diff --check`:** PASS.
- **SQL / role runtime:** nie wykonano prób SQL ani połączeń z bazą. Statyczny
  odczyt migracji 041 potwierdza złożone FK udziału do organizacji/materiału/
  lokalizacji, ochronę historii przez trigger oraz `GRANT SELECT, INSERT, UPDATE`
  bez `DELETE` dla roli runtime. Nie jest to dowód z uruchomionej bazy ani
  test obejścia SQL dla referencji operacji, której schemat nie istnieje.
- **Finanse, primary kitchen, SUPER ADMIN:** bez zmian.
- **Izolacja PostgreSQL 17:** nie tworzono projektu Docker Compose; nie wykonano
  testów DB ani migracji w instalacji.
- **Zasoby instalacyjne:** nie dotykano bazy, kontenerów, konfiguracji ani
  wolumenów instalacji. Nie wykonano deploymentu, commitu ani push.

Wykonane inspekcje repozytorium: `git status --short --branch`, ograniczone
`find` plików Stage 1D/1E/K3, oraz wyszukiwania `rg` tras, modeli, migracji,
metod RAW, odwołań `approved_revision_id`, workflow produkcyjnych, polityki
share i testów współbieżności. Nie uruchamiano testów ani komend migracyjnych.

## Dokumentacja i źródła

- [Stage 2F K2/K3 — zintegrowany projekt](./VELTRA_STAGE_2F_K2_K3_INTEGRATED_TECHNICAL_DESIGN.md)
  oraz [analiza wpływu K3.1–K3.21](./VELTRA_STAGE_2F_RAW_K3_TECHNICAL_IMPACT.md).
- [Stage 1D — raport implementacji](./VELTRA_STAGE_1D_IMPLEMENTATION_REPORT.md)
  wskazuje źródłową specyfikację RBAC w Google Docs; jej pełny tekst nie jest
  zapisany lokalnie. [Stage 1E — raport implementacji](./VELTRA_STAGE_1E_IMPLEMENTATION_REPORT.md)
  i [weryfikacja końcowa](./VELTRA_STAGE_1E_FINAL_VERIFICATION.md) opisują
  architekturę modułów, ale lokalny projekt Stage 2F odnotowuje brak źródłowej
  specyfikacji Stage 1E.
- [ADR-001](./VELTRA_ADR_001_MODEL_WDROZENIA_KLIENTA.md).
- Raporty i implementacje [3A](./VELTRA_STAGE_2F_IMPLEMENTATION_3A_RAW_LOCATION_SHARING_REPORT.md)
  oraz [3B](./VELTRA_STAGE_2F_IMPLEMENTATION_3B_RAW_APPROVED_REVISIONS_REPORT.md);
  ustalenia bieżące powyżej pochodzą z odczytu kodu, nie z samych raportów.

## Wymagana zależność do wznowienia 3C

Właściciel domeny produkcyjnej musi wskazać zatwierdzone wejście startu, model
trwającej operacji, moment uznania startu, kontrakt snapshotu materiału/jednostek,
zasady dozwolonej kontynuacji po zwykłym revoke oraz autorytatywne źródło
food-safety block i jego wpływ na trwającą operację. Do tego czasu nie należy
dodawać tabel, endpointów ani wyjątków kontynuacji. Nie rozpoczęto Implementation
3D i nie ogłasza się gotowości `RAW_MATERIALS`.

## Dostarczenie raportu

Raport zapisano lokalnie i przesłano jako Dokument Google do folderu
`RAPORTY_COPILOTA`. Zawartość wpisano w całości; status Dokumentów Google
potwierdził zapis na Dysku. Plik:
https://docs.google.com/document/d/1A3PLXqSpT0FUzo4f-gMCBRLB2saf_Zm0uw60Vv1S-JQ/edit
