# Stage 6A.1 — weryfikacja odtwarzania PostgreSQL

## Werdykt: PARTIAL

**Odtworzenie danych, właścicieli, ACL i dostępu aplikacyjnego z syntetycznymi sekretami przeszło test.** Nie potwierdzono jednak trwałego, niezależnego od środowiska Stage dostępu do rzeczywistych sekretów potrzebnych do odtworzenia działania aplikacji i ról. Dump jednej bazy nie zawiera globalnych ról ani ich haseł; dostępność `APP_KEY` i klucza podpisu poza działającym środowiskiem również nie wynika z dumpa. Znany plik konfiguracji znajduje się w przestrzeni sesji. Zgodnie z ograniczeniem nie użyto ani nie ujawniono rzeczywistych sekretów w teście.

**Następny krok:** właściciel powinien potwierdzić istnienie trwałego, bezpiecznego i dostępnego poza Stage źródła wymaganych sekretów oraz zachowanie poniższych metadanych i procedury recovery. Po rozstrzygnięciu tego ograniczenia można osobno autoryzować kontrolowane wdrożenie i migracje. Nie wdrażać Stage w ramach tego zadania.

## Metoda i izolacja

- Źródło: projekt `veltra-stage6a1`, PostgreSQL 17.11, istniejący wolumen `veltra-stage6a1_postgres-data`. Dostęp do źródła był wyłącznie odczytowy.
- Istniejące archiwum: SHA-256 `1ea1369b02f4014fee09e145998990e8d1b93e861f83ef44c5c54e10d382bf16`; kontrola pliku sum **PASS**. Nowego backupu nie tworzono.
- Jeden nowy PostgreSQL 17.11, z unikalną nazwą i etykietą, `--network none`, bez publikowanych portów, bez bind mountów i named volumes; dane wyłącznie w `tmpfs`. Nie podłączono wolumenu Stage ani wcześniejszego restore. Test połączenia Laravel użył krótkotrwałego kontenera obrazu wydania, współdzielącego jedynie izolowaną przestrzeń sieciową testowego PostgreSQL.
- Role utworzono z niezależnymi syntetycznymi hasłami. Dla testu podpisanej operacji **po porównaniu odtworzonych danych** zastąpiono klucz podpisu wyłącznie w disposable bazie syntetycznym kluczem. Rzeczywistych sekretów ani hashy haseł nie wypisywano i nie użyto do logowania.

## Odtworzenie ról, ownership i ACL

Źródło miało trzy role: właściciela/bazową rolę uprzywilejowaną `veltra`, ograniczoną rolę migratora `veltra_migrator` i ograniczoną rolę runtime `veltra_app`; członkostw tych ról i wpisów default ACL nie było. Porównano atrybuty ról, właściciela i ACL bazy, schematy, relacje, jawne ACL kolumn, funkcje, typy i rozszerzenia.

Pierwsze odtworzenie z `pg_restore --create`, przy innym użytkowniku bootstrap, wykazało odmienne ownership rozszerzeń i ich 224 funkcji oraz 12 typów, a także brak dwóch jawnych uprawnień właściciela sekwencji migracji. To rzeczywista luka w prostej procedurze restore, nie błąd danych biznesowych.

W **tym samym** izolowanym kontenerze usunięto tylko świeżo odtworzoną testową bazę, dopasowano nazwę testowej roli bootstrap i wykonano poprawioną procedurę:

1. Utworzyć globalne role z atrybutami odpowiadającymi źródłu; ich hasła uzyskać z osobnego bezpiecznego źródła, nie z dumpa. Bootstrap/właściciel bazy musi odpowiadać `veltra`; aplikacja nie używa tej roli uprzywilejowanej.
2. Utworzyć bazę `veltra_stage6a1` z właścicielem `veltra`, `UTF8`, `en_US.utf8` dla `LC_COLLATE` i `LC_CTYPE`; przyznać migratorowi CREATE bazy oraz ustawić właściciela schematu `public` na migratora.
3. Jako `veltra_migrator` wstępnie zainstalować zaufane rozszerzenia `btree_gist` i `pgcrypto` w `public`. Bootstrap `veltra` zapewnia właściwego właściciela obiektów ich skryptów.
4. Wykonać `pg_restore --exit-on-error` do przygotowanej bazy **bez `--no-owner` i bez `--no-acl`**. Odtworzyć ACL bazy: CONNECT dla runtime i migratora oraz CREATE dla migratora. Jako właściciel sekwencji przyznać `SELECT, UPDATE` na `public.migrations_id_seq` roli `veltra_migrator`; jej domyślne `USAGE` pozostaje. Nie nadawać runtime dodatkowych uprawnień.
5. Porównać katalogi i uruchomić kontrole uprawnień, dostępności sekretów oraz administratora przed dopuszczeniem aplikacji. Faktyczne odtworzenie Stage wymaga osobnej autoryzacji.

Po tej korekcie porównanie ze źródłem: **3/3 role**, baza i **2/2 schematy**, **73/73 relacje**, **4/4 jawne ACL kolumn**, **318/318 funkcji**, **156/156 typów** i **2/2 rozszerzenia** — zgodne ownership i efektywne ACL, łącznie z grantorami. Domyślne ACL i członkostwa: brak po obu stronach. Porównanie dotyczyło wskazanych obiektów bazy, nie wszystkich możliwych ustawień i zasobów całego klastra.

## Kontrole funkcjonalne

| Kontrola | Wynik |
|---|---|
| Restore | `pg_restore --exit-on-error` zakończył się pomyślnie. 49/49 nazw i batchy migracji, 70/70 liczby wierszy, 199/199 kontroli FK oraz zestawy relacji, indeksów, triggerów i funkcji zgodne z inwentaryzacją snapshotu. Odciski zawartości 70 tabel zgodne z wcześniej zachowanym izolowanym restore. Sekwencja migracji: `49`, `is_called=true` po obu stronach. |
| Definicje | Jak wcześniej, liczba tekstowych różnic to 53 constrainty i jeden widok; [poprzednia analiza](./VELTRA_STAGE6A1_RESTORE_DISCREPANCY_ANALYSIS.md) wyjaśniła różnice reprezentacji dla wcześniejszego odtworzenia tego samego archiwum. W bieżącym teście nie powtórzono kanonizacji każdego z 54 obiektów. |
| Migrator | Połączenie TCP z syntetycznym hasłem SCRAM; w transakcji wykonano CREATE w obu schematach, ALTER tabeli, DELETE bez zmiany wierszy, DROP obu widoków i REVOKE, po czym `ROLLBACK`. Brak testowych obiektów po rollbacku. To reprezentatywna próba, nie wykonanie 12 migracji. |
| Runtime | Połączenie TCP SCRAM i SELECT kluczowych tabel: PASS. Błędne hasło, dostęp do prywatnego klucza, CREATE tabeli oraz CREATE ROLE zostały odrzucone. Pierwsza konfiguracja testowego HBA dopuszczała lokalny TCP przez `trust`; wykryto to próbą błędnego hasła, skonfigurowano SCRAM wyłącznie w kontenerze testowym i powtórzono kontrole. |
| Laravel | Obraz backendu wydania połączył się z testową bazą jako runtime przy użyciu syntetycznych poświadczeń. Podpisana operacja Laravel na niezmienionej nazwie organizacji została zaakceptowana i wycofana; analogiczna operacja bez podpisu została odrzucona. Nie zmieniano RBAC ani readiness. |
| Administrator | Odtworzono jedną zainicjalizowaną organizację, aktywną lokalizację i aktywnego zweryfikowanego administratora z rolą i członkostwem. Faktycznego logowania tym kontem nie testowano. |

Wyniki są dowodem wskazanej procedury na kopii z określonego czasu, nie gwarancją aktualności kopii przy przyszłym oknie migracyjnym. Przed migracją ponownie sprawdzić aktualność backupu po wstrzymaniu zapisów.

## Sprzątanie i stan Stage

Po sprawdzeniu ID, etykiety, sieci i braku mountów usunięto tylko jednorazowy kontener `83d425f78ced08de9fa9126ca955978714ea5ffb294ab2aca5787709c87cc043` oraz lokalny plik syntetycznych poświadczeń. Test nie utworzył named volume. Wcześniejszy izolowany restore i jego wolumen zachowano; backup pozostał nietknięty. PostgreSQL Stage pozostał `healthy`, API health HTTP 200. Nie wykonano migracji Stage, wdrożenia ani zmian jego danych, ról, uprawnień lub modułów.
