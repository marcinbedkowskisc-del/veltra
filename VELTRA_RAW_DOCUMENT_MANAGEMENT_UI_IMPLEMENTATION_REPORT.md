# Raport implementacji UI zarządzania dokumentami RAW

## Podsumowanie

Dodano zarządzanie dokumentami bezpośrednio w istniejących formularzach tworzenia i edycji surowca oraz w widoku szczegółów. UI korzysta z istniejącego workflow stagingu, skanowania AV i autoryzowanych endpointów dokumentów; nie zastępuje backendu ani nie zmienia modelu uprawnień.

## Funkcjonalność i cykl życia

- Lista dokumentów pokazuje nazwę pliku, typ, stan skanowania i metadane. Widok szczegółów wybiera istniejący zakres `current` albo `editor`.
- Upload wysyła pliki do istniejącego stagingu. Stan przesłania jest odróżniony od zatwierdzenia i przypięcia do rewizji.
- Typ pliku w istniejącym kontrakcie backendowym to `OTHER`; UI nie wprowadza własnego wyboru typów.
- Stany `PENDING`, `CLEAN`, `INFECTED` i `ERROR` są prezentowane zgodnie z odpowiedzią backendu. Lista odświeża się podczas oczekiwania na skanowanie; ponowienie używa istniejącego endpointu.
- Dołączenie odbywa się dopiero przy zapisie materiału lub nowej rewizji przez istniejące `document_upload_ids`. Odpinanie jest przekazywane jako `detach_document_version_ids` podczas zapisu rewizji. UI nie obchodzi zależności od utrwalonej rewizji.
- Podgląd PDF/PNG/JPEG i pobieranie wywołują wyłącznie istniejące, autoryzowane endpointy. Pozostałe formaty są dostępne do pobrania, jeśli backend na to zezwala.

## Kontrakty backendu

Ponownie użyto endpointów listy dokumentów `GET /{material}/documents?view=current|editor`, stagingu `POST /document-uploads`, ponowienia `POST /document-uploads/{upload}/retry` oraz autoryzowanych endpointów preview/download wersji. Upload i skanowanie stagingu są synchroniczne; po przypięciu backend może ponownie skanować dokument, a lista może przejściowo zwrócić `PENDING`.

Lista nie zawierała typu dokumentu. Minimalnie rozszerzono jej odpowiedź o `document_type` przez dołączenie istniejącego rekordu dokumentu do istniejącej kwerendy. Nowe pole odzwierciedla obecne dane (`OTHER`), bez migracji i zmiany autoryzacji lub schematu.

## Bezpieczeństwo i obsługa błędów

- Nie zmieniono uwierzytelniania, autoryzacji organizacyjnej, izolacji kontekstu, przypisania do rewizji ani reguł prywatnego przechowywania.
- UI nie ujawnia ścieżek magazynu ani wewnętrznych tokenów. Pliki nie są udostępniane przed autoryzacją i pozytywną kontrolą backendu.
- Obsłużono odpowiedzi 401/419 (wygaśnięcie sesji), 403, 503, nieprawidłowe odpowiedzi, błędy walidacji i skanowania.
- Żądania listy można anulować; wyniki są powiązane z materiałem, kontekstem i widokiem, by nie pokazywać nieaktualnych danych po zmianie.
- Podgląd wykorzystuje Blob URL z czyszczeniem po zamknięciu/zmianie dokumentu.

## Zmienione pliki

- `backend/app/Domain/RawMaterials/Documents/RawMaterialDocumentSetService.php`
- `backend/tests/Concerns/RawDocumentsStage3eB3Tests.php`
- `frontend/src/App.css`
- `frontend/src/api.ts`
- `frontend/src/rawMaterials/RawMaterialCreateForm.tsx`
- `frontend/src/rawMaterials/RawMaterialCreateForm.test.tsx`
- `frontend/src/rawMaterials/RawMaterialDetailDrawer.tsx`
- `frontend/src/rawMaterials/RawMaterialEditForm.tsx`
- `frontend/src/rawMaterials/RawMaterialEditForm.test.tsx`
- `frontend/src/rawMaterials/api.ts`
- `frontend/src/rawMaterials/create.ts`
- `frontend/src/rawMaterials/RawMaterialDocumentsSection.tsx` (nowy)
- `frontend/src/rawMaterials/RawMaterialDocumentsSection.test.tsx` (nowy)
- `frontend/src/rawMaterials/RawMaterialDocumentsApi.test.ts` (nowy)

Nie zmieniono ani nie nadpisano istniejącego `VELTRA_RAW_PRE_STAGING_READINESS_ASSESSMENT.md`.

## Testy i wyniki

- Frontend `npm test -- --reporter=dot`: **292 testy PASS** (31 plików). Pierwsze uruchomienie miało pojedynczy nietrwały błąd w istniejącym teście katalogu; uruchomienie tego testu osobno oraz ponowienie całego zestawu zakończyły się powodzeniem.
- Frontend `npm run build`: **PASS** (TypeScript i build). Pozostało ostrzeżenie o rozmiarze jednego bundla ponad 500 kB.
- Frontend `npm run lint`: **PASS**; istnieje ostrzeżenie Fast Refresh w `src/visualPreview.tsx:39`.
- `RawMaterialDocumentStorageTest.php`: **11 testów / 75 asercji PASS**, w tym skanowanie ClamAV dla czystej i testowej próbki EICAR.
- `RawMaterialsTest.php --filter='stage3e_b3_'`: **24 testy / 217 asercji PASS**, obejmujące upload, skanowanie, retry, przypisanie do rewizji oraz kontrolę odczytu dokumentów.
- Pint dla dwóch zmienionych plików backendu: **PASS**.
- `git diff --check`: **PASS** w kontroli końcowej.

Pierwsze uruchomienie domyślnego kontenera testowego nastąpiło przed migracją świeżej, izolowanej bazy i zakończyło się błędami braku tabeli `public.organizations`. Następnie wykonano migracje wyłącznie na izolowanej bazie testowej, a powyższe wskazane testy przeszły. Nie zmieniano istniejących baz aplikacyjnych.

## Ograniczenia

- Kontrakt uploadu nie obsługuje wyboru typu dokumentu; staging używa typu `OTHER`.
- Podgląd ogranicza się do PDF, PNG i JPEG. Inne dozwolone formaty można pobrać.
- Backend nie udostępnia endpointu usuwania stagingu; nieużyte uploady wygasają według jego istniejącej polityki.
- Nie zmieniano gotowości ani aktywacji `RAW_MATERIALS` lub `BOSS_RAW_CATALOG_READ`.

## Raport Google Drive

W dostępnych narzędziach nie znaleziono konektora Google Drive do zapisu ani weryfikacji plików. Raport zachowano lokalnie; nie podejmowano prób przez alternatywną, niezweryfikowaną ścieżkę.

## Git i działania środowiskowe

Raport jest nowym plikiem. Wcześniejszy raport gotowości pozostaje nieśledzony i nietknięty. Zmiany nie zostały commitowane ani wypchnięte. Nie wdrażano aplikacji, nie zmieniano readiness/capabilities ani istniejących baz danych; migracje dotyczyły wyłącznie efemerycznej, izolowanej bazy testowej.
