# VELTRA 2.0 — ADR-001 — Model wdrożenia klienta

**Status:** zatwierdzona, wiążąca decyzja architektoniczna  
**Źródło decyzji:** [VELTRA 2.0 — ADR-001 — Model wdrożenia klienta](https://docs.google.com/document/d/1WzHvXJXW82ZaZ-ttiNulhqJ_soNV2pthcuF00xIablQ/edit)

## Decyzja

**JEDEN KLIENT = JEDEN SERWER = JEDNA INSTANCJA APLIKACJI = ODRĘBNE DANE KLIENTA.**

Każdy klient musi mieć własne, odizolowane:

- wdrożenie i instancję aplikacji;
- bazę PostgreSQL;
- instancję Redis;
- pliki;
- konfigurację;
- kopie zapasowe.

Nie wolno współdzielić tych zasobów ani danych pomiędzy klientami.

## Zachowane granice autoryzacji

Model jednego klienta na wdrożenie nie zastępuje ani nie osłabia istniejącego modelu autoryzacji aplikacji. W granicach każdej instancji nadal obowiązują zakresy organizacji i lokalizacji, kontrola członkostwa, jawne uprawnienia, audyt oraz weryfikacja dostępu po stronie aplikacji i bazy danych.

W szczególności oddzielne wdrożenia klientów nie są podstawą do pomijania `organization_id`, ograniczeń tenantowych, złożonych kluczy obcych ani kontroli kontekstu lokalizacji. Nie należy też rozluźniać constraintów instalacyjnych tylko po to, aby testować wiele klientów w jednej instancji; izolację klientów należy weryfikować zgodnie z zatwierdzonym modelem wdrożeń.

## Konsekwencje architektoniczne

1. Provisioning, aktualizacje, sekrety, monitoring i kopie zapasowe muszą być przypisane do pojedynczego klienta.
2. Przywracanie danych lub kopii klienta nie może wpływać na inną instalację.
3. API i domeny pozostają świadome organizacji/lokalizacji i egzekwują ich granice niezależnie od izolacji infrastrukturalnej.
4. Testy wieloklientowe obejmują odrębne środowiska wdrożeniowe; testy organizacji/lokalizacji sprawdzają granice autoryzacji wewnątrz pojedynczej instalacji.
5. Każda przyszła propozycja współdzielenia serwera, bazy, cache, plików, konfiguracji lub kopii zapasowych pomiędzy klientami wymaga zmiany tej zatwierdzonej decyzji.

## Zastosowanie

ADR-001 jest wiążący dla architektury i kolejnych etapów VELTRA 2.0, w tym Stage 2C. Zmiana infrastruktury wdrożeniowej nie oznacza automatycznej zmiany gotowości modułów ani zatwierdzenia wdrożenia.
