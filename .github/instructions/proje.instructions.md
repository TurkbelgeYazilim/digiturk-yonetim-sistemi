---
applyTo: '**'
---
-- [dbo].[user_groups].[id]=1 olan kullanıcılar tüm sayfalarda tam yetkiye sahip olmalı
-- [dbo].[tanim_sayfa_yetkiler].[kendi_kullanicini_gor]=1 ise, kullanıcı sadece kendi bilgilerini görebilmeli yani filtre alanında kullanıcı seçimi pasif olmalı ve sadece kendi kullanıcı bilgileri listelenmeli
-- Debug sadece [dbo].[user_groups].[id]=1 olan kullanıcılarda aktif olmalı