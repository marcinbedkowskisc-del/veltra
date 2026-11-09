# VELTRA 2.0 — Rejestr zmian decyzji RAW_MATERIALS

**Data przyjęcia:** 2026-11-09  
**Źródło:** jawna decyzja właściciela przekazana w zleceniu Implementation 3D.1.  
**Zakres:** niniejsze zmiany zastępują wyłącznie sprzeczne fragmenty K3.9–K3.10 i K3.18–K3.20. Poprzednie zatwierdzone dokumenty pozostają niezmienione i zachowują wartość historyczną.

## K3.9–K3.10 — zbiorcze udostępnianie RAW

- Udostępnianie zbiorcze jest **ściśle atomowe**: wszystkie cele przechodzą walidację i zapisują się albo nie zmienia się żaden.
- Częściowy sukces jest zabroniony. Jeden niedozwolony lub nieprawidłowy cel odrzuca całą operację.
- Administrator otrzymuje strukturalne powody odmowy dla celów, których szczegóły może poznać. Odpowiedź nie ujawnia tożsamości materiałów ani lokalizacji spoza jego zakresu.
- Występują dwa równoważne kierunki API: jeden materiał → wiele lokalizacji oraz wiele materiałów → jedna lokalizacja.
- Cała próba i końcowy wynik są audytowane; odrzucony audyt musi przetrwać rollback transakcji biznesowej.
- Duplikaty w żądaniu są deterministycznie redukowane, a już aktywne udostępnienia są zwracane jako bezpieczny, idempotentny brak zmiany.
- Walidacja musi być chroniona blokadami i ponowiona wewnątrz transakcji. Zmiany udziałów, rewizji, archiwizacji materiału i aktywności lokalizacji muszą być serializowane względem operacji zbiorczej.

## K3.18–K3.20 — formalne wycofanie RAW_MATERIALS

- Formalnym wycofaniem może zarządzać wyłącznie pierwotna tożsamość SUPER ADMIN.
- Zwykły ADMINISTRATOR nie może inicjować, zatwierdzać ani wykonywać wycofania.
- Wymóg drugiego administratora został wycofany; nie dodaje się zatwierdzenia przez drugą osobę.
- Wymagana jest świeża ponowna autentykacja hasłem i kompletny audyt.
- SUPER ADMIN nie omija integralności referencyjnej, blokad bezpieczeństwa żywności ani kontroli zależności.
- Wycofanie zachowuje historyczne rekordy i nie oznacza destrukcyjnego usunięcia danych.
- Wyłączenie modułu i formalne wycofanie pozostają odrębnymi operacjami.

## Pozostające decyzje właściciela przed endpointem wycofania

Niniejsza zmiana określa aktora i część zabezpieczeń, ale nie definiuje wykonywalnego kontraktu wycofania. Przed implementacją właściciel musi określić:

1. Stany i dozwolone przejścia formalnego wycofania oraz warunki uznania go za zakończone.
2. Pełny katalog zależności podlegających kontroli, ich źródła i zachowanie w razie awarii/nieosiągalności.
3. Źródło i semantykę blokad bezpieczeństwa żywności oraz fail-closed zachowanie, gdy stan jest nieznany.
4. Dokładne skutki wycofania dla dostępu, konfiguracji lokalizacyjnej, udostępnień, audytu i procesów w toku.
5. Semantykę odzyskiwania/anulowania po rozpoczęciu wycofania, bez usuwania historii.
6. Sposób egzekwowania świeżej ponownej autentykacji hasłem oraz wymagane pola audytu.

Do czasu zatwierdzenia powyższego nie tworzyć endpointu ani tabel formalnego wycofania. RAW_MATERIALS pozostaje `NOT_READY`; wyłączenie modułu nie jest zastępczym wycofaniem.
