# VELTRA 2.0 — raport implementacji awatarów PA

## 1. Zakres i funkcjonalność

- Dodano trwały stan awatara `INITIALS`, `LIBRARY` albo `UPLOAD`, uwzględniany w profilu i danych użytkownika PA.
- Samoobsługa pozwala wybrać ilustrację, wgrać i wykadrować zdjęcie albo wrócić do inicjałów. Administrator może wykonać te same operacje wobec użytkownika, którym może zarządzać w aktualnym kontekście.
- Współdzielony komponent prezentuje inicjały, ilustrację lub zdjęcie w menu konta, katalogu PA i szczegółach użytkownika; brakujące zdjęcie wraca do inicjałów.
- Operacje są audytowane istniejącym zdarzeniem `account.profile_update`. Nie dodano ról ani uprawnień, nie zmieniono BOSS/RAW, gotowości modułów ani aktywacji capabilities.

## 2. Galeria i licencje

- Galeria zawiera **64 stabilne identyfikatory**: 40 portretów i 24 wzory abstrakcyjne.
- Grafika SVG jest generowana lokalnie z kodu aplikacji; nie pobiera obrazów z zewnętrznych usług podczas użycia.
- DiceBear nie zostało użyte, ponieważ licencje poszczególnych stylów wymagają odrębnej weryfikacji ([licencje DiceBear](https://www.dicebear.com/licenses/)). Nie dodano zewnętrznych zasobów graficznych.
- `react-easy-crop` (MIT) obsługuje kadrowanie ([licencja projektu](https://github.com/ValentinH/react-easy-crop/blob/main/LICENSE)).

## 3. Upload i ochrona zdjęć

- Akceptowane są JPG, PNG i WebP do 5 MB; serwer sprawdza MIME i rzeczywisty obraz, ogranicza wymiary do kwadratu 4096×4096 oraz 12 mln pikseli i normalizuje wynik do WebP 256×256.
- Zdjęcia trafiają na prywatny dysk poza publicznym katalogiem. Dostarczenie wymaga istniejącej sesji i autoryzacji; odpowiedzi są `private, no-store` i mają `X-Content-Type-Options: nosniff`.
- Self-service nie przyjmuje docelowego ID użytkownika. Operacje administratora stosują istniejące sprawdzenie uprawnień i kontekstu. Zapis nowego pliku, zmiana rekordu, audyt i sprzątanie poprzedniego pliku są obsłużone bez ujawniania ścieżki storage.
- Wymagane jest GD z WebP; obraz backendu został skonfigurowany do jego budowania.

## 4. Interfejs

- Dodano polski panel samoobsługowy w menu konta i edycję awatara w szczegółach użytkownika PA.
- Kadrowanie pozwala ustawić pozycję i powiększenie, pokazuje podgląd kołowy i wysyła przycięty obraz.
- Kontrolki galerii są natywnymi przyciskami z nazwami dostępnymi i stanem wyboru; układ galerii i kadrowania dostosowuje się do szerokości ekranu.
- Zmiana użytkownika/kontekstu czyści stan edytora. Nieudane pobranie zdjęcia daje fallback do inicjałów; wersja zdjęcia odświeża widok po zastąpieniu pliku.

## 5. Zmienione pliki

**Backend**

- `backend/Dockerfile`
- `backend/composer.json`, `backend/composer.lock`
- `backend/config/filesystems.php`
- `backend/routes/api.php`
- `backend/app/Domain/Authorization/AdministrationUserService.php`
- `backend/app/Domain/Identity/UserAvatarService.php`, `UserAvatarType.php`
- `backend/app/Http/Controllers/Api/V1/UserAvatarController.php`
- `backend/app/Http/Requests/UserAvatarPhotoUploadRequest.php`, `UserAvatarSelectionRequest.php`
- `backend/app/Http/Resources/UserResource.php`
- `backend/app/Models/User.php`
- `backend/database/migrations/2026_11_22_000058_add_user_avatar_state.php`
- `backend/tests/Feature/UserAvatarTest.php`, `IdentityAuthenticationTest.php`

**Frontend**

- `frontend/package.json`, `frontend/package-lock.json`
- `frontend/src/App.tsx`, `AdministrationPanel.tsx`, `AuthPanel.tsx`, `api.ts`
- `frontend/src/administration/AdministrationUsersPage.tsx`, `AdministrationUsersPage.test.tsx`, `usersApi.ts`
- `frontend/src/avatars/AvatarArtwork.tsx`, `AvatarCropper.tsx`, `AvatarCropper.test.tsx`, `AvatarManagement.tsx`, `AvatarManagement.test.tsx`, `UserAvatar.tsx`, `UserAvatar.test.tsx`, `avatarApi.ts`, `avatarTypes.ts`, `avatars.css`

## 6. Weryfikacja

- Frontend: **273 testy PASS** (29 plików).
- Frontend build i TypeScript: **PASS**; Vite zgłasza istniejący próg ostrzegawczy dla chunka 544,92 kB (>500 kB).
- Frontend lint: **PASS** z jednym ostrzeżeniem w niezmienionym `src/visualPreview.tsx:39` (`react(only-export-components)`).
- Backend — testy skupione na awatarach, tożsamości, administracji i katalogu BOSS: **58 PASS / 522 asercje**.
- Wcześniej wskazane zestawy `RawMaterialCompatibilityAssessmentTest` i `RawMaterialDocumentStorageTest`: **18 PASS / 151 asercji**. Sprawdzenie `clamdscan --ping=1` oraz PING po TCP z kontenera testowego zwróciło `PONG`; test skanowania próbek CLEAN i EICAR przeszedł.
- Pełny backend suite: **560 PASS / 5 535 asercji**, `vendor/bin/phpunit` z `memory_limit=512M` w izolowanym kontenerze testowym (14:42.893; PHPUnit raportował 103 MB). Bez tego limitu uruchomienie zatrzymało się na 128 MB podczas `RawDocumentsStage3eB3Tests.php:84`; kod i testy nie były modyfikowane.
- Pint dla zmienionych plików PHP: **PASS** (9 plików).
- `git diff --check`: PASS dla zmienionych plików śledzonych; raport Markdown sprawdzono przy przeglądzie treści.

## 7. Pozostałe uwagi

- Brak blokera funkcjonalnego dla ścieżek awatarów objętych testami.
- Pełna walidacja suite wymaga limitu pamięci większego niż domyślne 128 MB dla testu `RawDocumentsStage3eB3Tests.php:84`; przy 512 MB pełny suite przeszedł.
- Build raportuje rozmiar głównego chunka powyżej 500 kB; lint raportuje ostrzeżenie w niezmienionym pliku.
- Podczas aktualizacji lockfile Composer wypisał: **7 security vulnerability advisories affecting 4 packages**. Instalacja nie podała identyfikatorów advisory, nazw pakietów ani severity; odsyłała do `composer audit` po pełną listę. Zgodnie z zakazem nowego audytu nie uruchamiano `composer audit`, nie ustalano więc szczegółów ani nie aktualizowano zależności.

## 8. Migracja, środowisko i status Git

- Dodano migrację `2026_11_22_000058_add_user_avatar_state.php`; wykonywano ją wyłącznie w izolowanym środowisku testowym na bazie testowej. Nie zmieniano istniejących baz aplikacyjnych.
- Stan końcowy po aktualizacji raportu: gałąź `main`; 35 plików implementacji oraz ten raport (łącznie 36 ścieżek) pozostają lokalne i niezatwierdzone. Końcowe `git status` nie wykazuje innych zmian ani artefaktów builda.

## 9. Potwierdzenia

- Nie wykonano commitu, pushu ani wdrożenia.
- Nie aktywowano capabilities ani nie zmieniano gotowości `BOSS_RAW_CATALOG_READ` lub `RAW_MATERIALS`.
- Raport przeznaczony do folderu Google Drive `RAPORTY_COPILOTA` (`1I2r4uTKcpl69E3dXOru18VOLi74N613t`); zweryfikowany link do pliku znajduje się w odpowiedzi końcowej.
