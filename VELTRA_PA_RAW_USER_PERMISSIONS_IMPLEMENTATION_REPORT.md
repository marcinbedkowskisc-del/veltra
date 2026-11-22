# VELTRA 2.0 — Uprawnienia RAW w szczegółach użytkownika PA

## Zakres

Dodano odczyt, prezentację i obsługę nadawania/cofania organizacyjnych uprawnień RAW w istniejących szczegółach użytkownika Panelu Administracyjnego. Nie utworzono osobnego systemu użytkowników, nowej trasy odczytowej ani migracji.

## Implementacja

- Odpowiedź istniejącego endpointu szczegółów użytkownika PA zawiera `raw_permissions`: granty, status efektywności, daty, grantorów i cofających, źródło, łańcuch delegowania oraz historię nadań/cofnięć.
- Odczyt jest ograniczony istniejącą autoryzacją PA i organizacją aktora. Efektywność wylicza `RawMaterialAuthorization`; odczyt nie wymaga gotowości `RAW_MATERIALS`.
- Dodano sekcję „Uprawnienia RAW” z polskimi nazwami, rozróżnieniem przypisania i efektywności, źródłem, linią delegowania, okresem ważności, historią, stanami pustymi i objaśnieniami ograniczeń odbiorcy.
- Nadawanie i cofanie korzysta wyłącznie z istniejących endpointów i `OrganizationRawMaterialPermissionAdministration`. Formularze wymagają powodu i potwierdzenia; odmowy 403, 503 oraz wygasłą sesję obsługują jawnie.
- Zmiana użytkownika czyści poprzednie szczegóły; odczyt jest anulowany przy zmianie użytkownika. Zmiana kontekstu remountuje stronę przez istniejący `contextKey` w `AdministrationPanel`.
- Nie nadano automatycznych uprawnień administratorom. Rola Administratora PA sama nie zapewnia prawa delegowania ani prawa źródłowego.

## Ponownie użyte kontrakty

- `RawMaterialAuthorization::effectiveGrantIds()` — status efektywny i ważność źródeł.
- `OrganizationRawMaterialPermissionAdministration::grant()` / `revoke()` oraz istniejące trasy RAW — jedyne ścieżki mutacji.
- `AdministrationUserService::show()` — istniejąca organizacyjnie ograniczona odpowiedź szczegółów PA.

Mutacje pozostają zależne od istniejącej gotowości modułu RAW oraz autoryzacji delegującego; UI informuje o tym i nie omija tych warunków. Nie zmieniono RBAC, uprawnień, dostępności ani gotowości modułów.

## Zmienione pliki

- `backend/app/Domain/Authorization/AdministrationUserService.php`
- `backend/tests/Feature/AdministrationUsersTest.php`
- `frontend/src/App.css`
- `frontend/src/administration/AdministrationUsersPage.tsx`
- `frontend/src/administration/AdministrationUsersPage.test.tsx`
- `frontend/src/administration/usersApi.ts`
- `frontend/src/administration/RawPermissionsManagement.tsx` — nowy
- `frontend/src/administration/rawPermissionsApi.ts` — nowy
- `VELTRA_PA_RAW_USER_PERMISSIONS_IMPLEMENTATION_REPORT.md` — ten raport

Migracje: brak.

## Weryfikacja

- Frontend: pełna suita — **278 testów PASS / 29 plików**; testy PA RAW celowane — **13 PASS**.
- Build i TypeScript: **PASS** (Vite zgłosił istniejące ostrzeżenie o rozmiarze chunku).
- Lint: **PASS** (pozostaje ostrzeżenie `react(only-export-components)` w `visualPreview.tsx`, poza zakresem).
- Backend PA: `AdministrationUsersTest` — **6 testów PASS / 56 asercji**, w tym delegowany grant, linia źródłowa, efektywność, brak gotowości RAW i izolacja organizacji.
- Backend RAW: wybrane testy delegowania, odmowy administratorowi bez authority i odmowy samonadania — **3 PASS / 21 asercji**.
- Regresje BOSS i awatarów PA: API, autoryzacja, provenance i `UserAvatarTest` — **35 PASS / 259 asercji**.
- Pint dla dwóch zmienionych plików PHP: **PASS**.
- `git diff --check`: **PASS**.
- Backend uruchomiono wyłącznie przez zatwierdzone skrypty dla odizolowanej bazy testowej; nie wykonywano operacji na istniejących bazach.

## Status i ograniczenia

- Brak nierozwiązanego blokera implementacyjnego.
- Wykonanie nadania/cofnięcia może być niedostępne, gdy istniejące RAW nie jest gotowe albo operator nie ma efektywnego `RAW_MATERIALS_PERMISSION_DELEGATE` i źródłowego prawa.
- Branch: `main`; HEAD pozostaje `b144a61e845e49cf1e9b880c87a17c9badf8e182`.
- Worktree zawiera wyłącznie wymienione zmiany oraz ten raport.
- Nie wykonano commitu, pushu, wdrożenia ani aktywacji; `RAW_MATERIALS` i pozostałe gotowości pozostają bez zmian.
