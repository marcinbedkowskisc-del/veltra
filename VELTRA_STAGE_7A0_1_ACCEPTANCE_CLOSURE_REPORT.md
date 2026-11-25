# VELTRA 2.0 — Stage 7A.0.1 — Raport zamknięcia akceptacji

## Werdykt

**AKCEPTACJA NIEZAMKNIĘTA — capability jest aktywna i konto BOSS provisionowano, ale nie udało się potwierdzić uwierzytelnionej sesji BOSS ani wykonać żądanych testów dostępu.**

Nie wykonano dalszych prób logowania po dwóch odczytach HTTP 401. Nie odczytywano, nie zapisywano ani nie proszono o hasła, linki aktywacyjne, cookies lub tokeny.

## HTTP 409 — przyczyna i rozwiązanie

Uwierzytelniony odczyt `GET /api/v1/security/organization-capabilities/BOSS_RAW_CATALOG_READ` zwrócił:

- `readiness=READY`
- `configured_state=ENABLED`
- `available=true`
- `state_version=1`

Historia audytu zawiera 6 zdarzeń:

- pierwsza próba aktywacji została zaakceptowana z oczekiwaną wersją 0; przejście `DISABLED → ENABLED` zakończyło się wersją 1;
- cztery późniejsze próby zostały odrzucone z `detail_code=state_version_conflict`: nadal przesyłały `expected_state_version=0`, podczas gdy bieżąca wersja wynosiła 1.

**Dokładna przyczyna 409:** nieaktualna wersja w payloadzie po udanym pierwszym żądaniu. Dialog nadal wysyłał wersję 0; nie był to dowód błędnego hasła. Pierwsze żądanie przeszło potwierdzenie administratora i zostało zapisane w audycie jako aktywacja. Konflikt rozwiązano przez odczyt aktualnego stanu i historii; ponieważ capability była już włączona, nie ponawiano aktywacji ani nie proszono o kolejne potwierdzenie hasła.

## Konto BOSS

Przez istniejący panel administracyjny provisionowano jeden nowy rekord; panel zwrócił komunikat o wysłaniu linku aktywacyjnego, a uwierzytelniony katalog administratora pokazał łącznie jedno konto z rolą BOSS. Odczyt szczegółów wykazał:

- konto aktywne;
- dokładnie jedną aktywną rolę: `BOSS` (jeden historyczny przydział roli);
- jedno aktywne członkostwo w aktywnej lokalizacji typu `CENTRAL_KITCHEN`;
- szczegóły administracyjne nie zwróciły efektywnych dodatkowych grantów RAW.

Przy ostatnim odczycie administracyjnym adres e-mail konta nie był jeszcze zweryfikowany. Panel wysyłał użytkownikowi link aktywacyjny do samodzielnego ustawienia hasła. Nie zmieniano istniejącego konta administratora, nie nadano dodatkowych praw administracyjnych i nie tworzono drugiego konta.

## Uwierzytelniona akceptacja BOSS

Po zgłoszeniu przez właściciela, że sesja BOSS jest gotowa, udostępniona strona przeglądarki nadal zwracała `HTTP 401` dla `/api/v1/auth/me` i `/api/v1/auth/operating-location`. Interfejs pozostawał w stanie niezalogowanym z formularzem logowania. Dlatego nie można było ustalić, czy problem wynika z niezakończonej aktywacji, wygasłej sesji, czy z innego kontekstu przeglądarki; nie zakładano błędnego hasła.

| Wymagane sprawdzenie | Status | Wynik |
|---|---|---|
| Capability `BOSS_RAW_CATALOG_READ` READY i ENABLED | CONFIRMED | READY, ENABLED, `available=true`, wersja 1. |
| Audyt aktywacji | CONFIRMED | Zaakceptowana aktywacja i przejście do wersji 1; cztery późniejsze odrzucenia z powodu nieaktualnej wersji. |
| Provisioning BOSS: jedna rola i lokalizacja | CONFIRMED | Jeden aktywny przydział roli BOSS i aktywne członkostwo w kuchni centralnej. |
| Zalogowanie BOSS w udostępnionej przeglądarce | NOT VERIFIED | Dwa żądania uwierzytelnione zwróciły 401. |
| Odczyt istniejących danych RAW jako BOSS | NOT VERIFIED | Nie wykonano bez prawidłowej sesji BOSS. |
| Domyślny dostęp FOODCOST_VIEW i PURCHASE_PRICES_VIEW | NOT VERIFIED | Nie potwierdzono efektywnego dostępu ani pól finansowych w odpowiedzi API BOSS. |
| Brak możliwości edycji danych przez BOSS | NOT VERIFIED | Nie wykonano testów API bez sesji BOSS. |
| Brak dostępu BOSS do Panelu Administracyjnego | NOT VERIFIED | Nie wykonano testu jako zalogowany BOSS. |
| `RAW_MATERIALS` i rekordy biznesowe bez zmian | PARTIALLY IMPLEMENTED | Poprzedni raport wdrożenia potwierdzał RAW `READY` i `ENABLED`. W tej próbie nie zmieniano danych RAW, ale nie wykonano ponownego odczytu stanu ani liczników po provisioningu. |

Wcześniejsze testy izolowane (65 testów / 761 asercji) nie zastępują uwierzytelnionej weryfikacji konta BOSS w Stage6a1. Nie uruchamiano regresji ani operacji modyfikujących dane biznesowe.

## Wymagane dokończenie

1. Właściciel powinien sprawdzić w lokalnym Mailpit, czy link aktywacyjny dla nowego konta został odebrany, i dokończyć aktywację oraz ustawienie hasła lokalnie.
2. Zalogować BOSS w **udostępnionej** przeglądarce Stage6a1 i wybrać kuchnię centralną. Nie przekazywać danych logowania w czacie.
3. Po potwierdzeniu sesji wykonać wyłącznie odczytowe testy: katalog RAW i pola danych, FOODCOST_VIEW, PURCHASE_PRICES_VIEW, brak operacji edycji oraz brak dostępu do panelu administracyjnego.
4. Ponownie odczytać status `RAW_MATERIALS` i porównać liczbę istniejących rekordów z wcześniej zweryfikowanym stanem.

## Stan repozytorium i zakres

- Repozytorium: `/home/veltra/projekty/veltra`
- Gałąź: `feature/stage7a-rbac-alignment`
- HEAD: `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`
- W ramach tej czynności nie zmieniano kodu, nie wykonywano migracji, wdrożenia, commit, push ani merge.
- Istniejące modyfikacje robocze i raporty zachowano. Jedynym plikiem utworzonym w ramach tej czynności jest niniejszy raport.
