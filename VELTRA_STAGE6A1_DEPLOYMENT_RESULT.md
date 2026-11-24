# Stage 6A.1 — wynik wdrożenia

**DEPLOYED — Stage 6A.1 updated successfully.** Wdrożono lokalnie zatwierdzony commit `795356faae84bd2185d615dc3764c97811d07184`. Usługi działają; poniższe ograniczenia testów wymagają ręcznej weryfikacji po zalogowaniu.

## Migracje i zabezpieczenie danych

- Po zatrzymaniu starego frontendu, backendu i queue potwierdzono brak innych klientów PostgreSQL i transakcji przygotowanych. Wykonano nowy backup przed migracją: `/home/veltra/backups/stage6a1/veltra-stage6a1-pre-deployment-20261123T234043Z-ae6d29af870e4867b7c0f0d25d660313.dump`, 677466 B, tryb `600`, SHA-256 `7f2df7825b5a91f1100e7287423a536b4d293b3d4733886c11d8d76691382495`. `pg_dump`, lista oraz pełny odczyt archiwum przez `pg_restore` i niezależna weryfikacja sumy: PASS. Wcześniejszą kopię i zasoby restore zachowano.
- Jednokrotny migrator wykonał 12 oczekujących migracji od `000050` do `000059`, wszystkie DONE. Historia zawiera dokładnie 61 nazw zgodnych z zatwierdzonym źródłem; 12 jest w batchu 5. Sprawdzono 250 relacji FK i wskazany wcześniej niezawalidowany constraint: brak naruszeń.
- Odciski danych użytkowników, przypisań ról, grantów, członkostw, organizacji, lokalizacji i stanów modułów odpowiadają stanowi przed migracją; nowe kolumny awatara wyłączono z porównania użytkowników. Jeden aktywny, zweryfikowany administrator nadal ma rolę i aktywne członkostwo. RAW pozostaje włączony dla aktywnej lokalizacji; tabela stanów capability BOSS ma zero wpisów. Nie zmieniano ręcznie RBAC ani aktywacji.

## Usługi i kontrole

- Backend: `veltra-stage6a1-backend:rc-795356faae84-20261123`, ID `sha256:63e14f50f0dffe93b07b7fe64d23d021a49ee71956326a48f84fb76ca55456d6`, **healthy**. Migrator i queue użyły tego samego ID.
- Queue: działa proces `queue:work redis`, bez restartów; Redis odpowiada PONG. Kolejka domyślna była pusta. Nie wysłano zadania testowego i nie potwierdzono przetworzenia zadania end-to-end.
- Frontend: `veltra-stage6a1-frontend:rc-795356faae84-20261123-complete`, ID `sha256:85a24393a503d83075a3efc0c88f06b59c58414ccd4ebe1b793e58852ae5c4fe`, **healthy**. [Frontend lokalny](http://127.0.0.1:5174/) wyświetla się w przeglądarce; status wskazuje działający backend.
- API `/api/health` bezpośrednio i przez frontend: HTTP 200. Niezalogowane żądania do 10 chronionych endpointów konta, administracji, jednostek, dostawców, alergenów, RAW i BOSS zwróciły HTTP 401; ekran RAW wymaga logowania i wyboru lokalizacji.
- Uruchomione w obrazie backendu walidatory tworzenia i edycji: PASS dla braku ceny, poprawnego `NOMINAL_UNIT` i `PACKAGE` oraz odrzucenia brakującej/niedozwolonej jednostki, ceny ujemnej i błędnego kodu. W transakcji tylko do odczytu przeliczono `PACKAGE`, `NOMINAL_UNIT` i `LEGACY_BASE_UNIT` oraz sprawdzono jednostki kg/g i odrzucenie nieistniejącej jednostki: PASS. Zainicjalizowano 6 definicji jednostek i 8 źródeł alergenów.
- Nowe logi usług nie zawierały linii błędów ani wyjątków. PostgreSQL i Redis pozostały uruchomione, zdrowe i mają wcześniejsze ID kontenerów oraz wolumeny `veltra-stage6a1_postgres-data` i `veltra-stage6a1_redis-data`. Migrator zachowano wraz z logami.

## Ograniczenia i następny krok

Nie było dostępnej zalogowanej sesji administratora: rzeczywistego logowania, dostępu administratora, RBAC dla zalogowanych użytkowników ani chronionych odpowiedzi API dostawców, alergenów i RAW nie sprawdzono. Walidacja i przeliczenia powyżej nie zastępują end-to-end tworzenia/edycji RAW, kontroli jednostek archiwalnych ani testu przetworzenia kolejki. Nie tworzono zbędnych rekordów biznesowych. Następny krok: właściciel loguje się na istniejące konto i wykonuje te ręczne testy funkcjonalne.

Nie zmieniono commita `main`, nie wykonano pusha ani merge. Worktree zatwierdzonego kandydata pozostaje czysty. Upload zredagowanego raportu na Google Drive jest niedostępny bez logowania; raport pozostaje lokalnie.
