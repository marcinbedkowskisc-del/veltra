# VELTRA 2.0 — raport remediacji architektury autoryzacji

## Podsumowanie

Wprowadzono migrację `2026_11_05_000022`, która wymusza podpisany kontekst mutacji na poziomie PostgreSQL, oraz uzupełniono przepływy aplikacyjne, uprawnienia ról, provisioning i dokumentację. Zmiany obejmują zabezpieczenia omówione w reaudicie migracji 021 i testy wyłącznie na odizolowanych zasobach disposable.

To raport z remediacji, nie niezależna akceptacja zabezpieczeń. Przed zamknięciem ustaleń wymagana jest niezależna reaudytacja.

## Przyczyna i zakres

Dotychczasowe kontrole autoryzacji nie tworzyły jednej, spójnej granicy dla wszystkich mutowalnych tabel dostępnych dla roli runtime. Testy runtime ujawniły także nadmiarowe prawa do rejestru użyć operacji uprawnień, brak triggerów na części tabel z DML, odrzucanie poprawnych zakresów zawierających wielkie litery oraz utratę zdarzeń audytowych dla odrzuconych konfiguracji modułów.

## Wprowadzone zabezpieczenia

- Aplikacja tworzy bilet mutacji HMAC obejmujący aktora, zakres, nonce, termin ważności 30 sekund i identyfikator bieżącej transakcji. PostgreSQL weryfikuje podpis, transakcję, termin i zgodność zakresu z tabelą oraz operacją.
- Migracja 022 sprawdza role runtime i migracyjną oraz rejestruje w schemacie `veltra_private` klucz podpisujący, allowlisty tabel i sekwencji oraz dozwolone zakresy. Rola runtime nie ma dostępu do prywatnego schematu, praw `TRUNCATE`, prawa tworzenia obiektów w `public` ani członkostwa w rolach.
- Każda mutowalna tabela z prawami DML runtime jest objęta triggerem granicy mutacji. Rejestr `raw_material_permission_grant_operation_uses` oraz katalog `raw_materials_currency_codes` pozostają dla runtime tylko do odczytu.
- Provisioning ponownie nakłada ograniczone granty w sposób idempotentny; ponowne uruchomienie nie przywraca runtime praw zapisu do chronionego rejestru.
- Zakresy z wielkimi literami są akceptowane, a odrzucone cele konfiguracji modułów zachowują poprawny zapis audytowy.
- Dla istniejącej bazy migracja fail-closed wymaga ręcznej atestacji `APP_DB_AUTHORIZATION_HISTORY_ATTESTATION=REVIEWED:<nazwa_bazy>:2026_11_05_000022`. Atestacja i lista niepustych tabel są zapisywane w niedostępnym dla runtime schemacie prywatnym. Świeża baza bez chronionej historii przechodzi bez atestacji.
- `README.md` i `compose.yaml` opisują procedurę atestacji oraz przekazują jej wartość do kontrolowanego procesu migracji.

Nie zmieniano wcześniejszych migracji. Zmienione przepływy aplikacyjne obejmują m.in. identity, installation, role, członkostwa, konfigurację modułów i RAW_MATERIALS.

## Wyniki weryfikacji

Wszystkie testy wykonano na dedykowanych, disposable kontenerach PostgreSQL 17 i Redis. Nie użyto baz ani wolumenów działającej aplikacji.

| Weryfikacja | Wynik |
|---|---|
| Pełna regresja backendu po końcowym formatowaniu | PASS — 182 testy, 1522 asercje, 1 pominięty test |
| Testy granicy mutacji i bootstrapu RBAC | PASS — 16 testów, 168 asercji, 1 pominięty test |
| Próba sfałszowania nadania przez tymczasowe tabele i `search_path`, wykonana niezależnie na rzeczywistych poświadczeniach runtime | PASS — PostgreSQL odrzucił INSERT do trwałego rejestru nadań, SQLSTATE `42501` |
| PHP syntax check dla `app`, `tests` i `database` | PASS — wykonany w kontenerze PHP |
| Laravel Pint dla zmienionych plików PHP | PASS |
| `git diff --check` | PASS |
| Świeże migracje 001–022 | PASS |
| Upgrade 001–021 → 022 z istniejącą historią bez atestacji | PASS — migracja przerwała się atomowo; wersja pozostała 021, dane zachowano, schemat prywatny nie został utworzony |
| Upgrade 001–021 → 022 z poprawną atestacją | PASS — migracja zakończyła się, zachowując istniejący rekord i rejestrując listę sprawdzonych tabel |
| Dwukrotne uruchomienie provisioningu | PASS — runtime nie odzyskał zabronionych praw zapisu |
| Walidacja Compose z syntetycznymi wartościami środowiska | PASS |

Pominięty test: `RawMaterialPermissionBootstrapTest::test_runtime_cannot_shadow_bootstrap_validation_relations_with_temporary_tables`. Test wymaga, aby główne połączenie testowe używało roli runtime, lecz jego fixture tworzy użytkowników bez podpisanego kontekstu i dlatego nie nadaje się do takiego połączenia po wprowadzeniu migracji 022. Odpowiedni scenariusz ataku z tymczasową tabelą audytu, manipulacją `search_path` i próbą trwałego nadania został dodatkowo uruchomiony bezpośrednio na poświadczeniach runtime i zakończył się odmową `42501`. Nie zmieniono ani nie usunięto pominiętego testu.

W pierwszej próbie pełnej regresji użyto niezgodnego klucza aplikacyjnego i niepełnej konfiguracji domen Sanctum, co spowodowało błędy testów sesji. Po dopasowaniu konfiguracji do wartości używanej przez środowisko testowe, wyłącznie w bazie disposable, pełna regresja zakończyła się wynikiem podanym wyżej.

## Ryzyka i wymagane działania wdrożeniowe

1. Migracja istniejącej instalacji pozostaje zablokowana do czasu ręcznego przeglądu wszystkich wskazanych historycznych tabel i podania dokładnej atestacji dla właściwej nazwy bazy. Atestacja potwierdza wykonanie przeglądu przez administratora; nie zastępuje jego oceny poprawności danych.
2. Przed migracją należy zweryfikować konfigurację rozdzielonych ról migracyjnej i runtime oraz synchronizację `APP_KEY` z kluczem zarejestrowanym przez migrację. Zmiana klucza wymaga kontrolowanej procedury; nie wolno kopiować kluczy ani sekretów testowych do produkcji.
3. Testy nie zmieniały działających kontenerów, baz ani wolumenów i nie dowodzą poprawności danych historycznych konkretnej instalacji.
4. Jeden historyczny test pozostał pominięty z powodu niezgodnego sposobu tworzenia fixture’ów. Odpowiadająca mu bezpośrednia próba SQL na runtime została wykonana, ale osobna poprawka fixture’u/testu nie wchodziła w zakres tej remediacji.
5. Niezależna reaudytacja powinna sprawdzić migrację 022, dokładne granty ról, wszystkie mutowalne tabele i atestację danych dla konkretnego celu wdrożenia.

## Ograniczenia i stan

- Nie wykonywano migracji ani wdrożenia na działającej aplikacji.
- Nie modyfikowano istniejących baz ani wolumenów aplikacji.
- RAW_MATERIALS pozostał nieaktywny poza fixture’ami transakcyjnymi testów.
- Nie rozpoczęto Stage 2D.
- Nie utworzono commitów ani nie wykonano push.

