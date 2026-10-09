# Mačja besedila — pregled za Davida (M5-R06-06)

> **Status:** osnutek **Claude, 9. 10. 2026 — čaka Davidov pregled** pred vklopom mačk (M5-R06-09; CAT_SPEC: David prebere angleščino pred vklopom). Mačke so skrite (`PETPREP_CATS_ENABLED=false`), zato teh besedil zdaj ne vidi nihče.
> **Kako pregledati:** en prehod po tabeli. Če je vrstica v redu, nič ne naredi; popravke napiši v stolpec ali v klepet (ključ + novo besedilo). Pasja besedila (2. stolpec) so samo za primerjavo in se **ne spreminjajo** (test `DogPushTextSnapshotTest`).
> **Vir resnice:** `backend/lang/en/push.php` in `backend/lang/sl/push.php` (podimenski prostor `cat`), `backend/lang/*/account.php`. Tabela je ustvarjena iz teh datotek; po popravku jo ustvari znova.

## Pravila, ki veljajo za vsa besedila

- Brez imen otrok in živali (zaklenjen zaslon je javen, Expo / Apple / Google so tretje osebe). Edina spremenljivka je `:time`.
- Otrok: »ti«, kratko, toplo, nikoli strašljivo ali sramotilno. Starš: dejstva (pasja besedila za starše uporabljajo »Tvoj otrok …« iz PRODUCT_SPEC §6 — mačja sledijo istemu vzorcu).
- Slovenščina: **»muca« je ženskega spola** (»muca je lačna«, »bo zbolela«, »jo / ji«). En samostalnik za vse starosti, kot pri psu (pasja obvestila mladička ne ločijo) — glej odprto vprašanje 1.
- Angleščina: *cat*, zaimek *it* (kot *dog … it*) — glej odprto vprašanje 2.
- M3-12: obvestilo nikoli ne zahteva dejanja, ki ga aplikacija zavrne. Ko je odprto samo praskanje, hrana in voda čakata na **praskalnik** (čiščenje praskanja ne razreši) — vrstici `scratcher_first`.

## Tabela

| Ključ | EN pes (za primerjavo) | EN mačka | SL mačka |
|---|---|---|---|
| `push.cat.soft.hunger` | Your dog is giving you a gentle look and pointing at the food bowl. | Your cat is giving you a gentle look and sitting by the food bowl. | Tvoja muca te milo gleda in sedi ob posodi s hrano. |
| `push.cat.soft.thirst` | Your dog is giving you a gentle look and pointing at the empty water bowl. | Your cat is giving you a gentle look and sitting by the empty water bowl. | Tvoja muca te milo gleda in sedi ob prazni posodi za vodo. |
| `push.cat.soft.hygiene` | Your dog is giving you a gentle look and pointing at a mess that needs cleaning up. | Your cat is giving you a gentle look — there’s a mess next to the litter tray that needs cleaning up. | Tvoja muca te milo gleda — zraven peska je nered, ki ga je treba počistiti. |
| `push.cat.critical.hunger` | If you don’t feed your dog within 30 minutes, it will get sick. | If you don’t feed your cat within 30 minutes, it will get sick. | Če je ne nahraniš v 30 minutah, bo zbolela. |
| `push.cat.critical.thirst` | If you don’t give your dog water within 30 minutes, it will get sick. | If you don’t give your cat water within 30 minutes, it will get sick. | Če ji ne daš vode v 30 minutah, bo zbolela. |
| `push.cat.critical.hygiene` | Your dog made a mess! Clean it up as soon as you can, or it will get sick. | Your cat made a mess next to the litter tray! Clean it up as soon as you can, or it will get sick. | Muca je naredila nered zraven peska! Počisti ga čim prej, sicer bo zbolela. |
| `push.cat.wait.hunger` | Your dog is getting hungry. The next meal is at :time — don’t forget it. | Your cat is getting hungry. The next meal is at :time — don’t forget it. | Tvoja muca postaja lačna. Naslednji obrok je ob :time — ne pozabi nanj. |
| `push.cat.wait.thirst` | Your dog is thirsty. You can give it water again at :time — don’t forget it. | Your cat is thirsty. You can give it water again at :time — don’t forget it. | Tvoja muca je žejna. Vodo ji lahko spet daš ob :time — ne pozabi nanjo. |
| `push.cat.clean_first.hunger` | Your dog is hungry, but the mess has to be cleaned up first. Then you can feed it. | Your cat is hungry, but the mess has to be cleaned up first. Then you can feed it. | Tvoja muca je lačna, a najprej je treba počistiti nered. Potem jo lahko nahraniš. |
| `push.cat.clean_first.thirst` | Your dog is thirsty, but the mess has to be cleaned up first. Then you can give it water. | Your cat is thirsty, but the mess has to be cleaned up first. Then you can give it water. | Tvoja muca je žejna, a najprej je treba počistiti nered. Potem ji lahko daš vodo. |
| `push.cat.scratcher_first.hunger` | — (samo mačka) | Your cat is hungry, but first carry it to the scratching post and praise it. Then you can feed it. | Tvoja muca je lačna, a najprej jo odnesi na praskalnik in jo pohvali. Potem jo lahko nahraniš. |
| `push.cat.scratcher_first.thirst` | — (samo mačka) | Your cat is thirsty, but first carry it to the scratching post and praise it. Then you can give it water. | Tvoja muca je žejna, a najprej jo odnesi na praskalnik in jo pohvali. Potem ji lahko daš vodo. |
| `push.cat.clean_and_scratcher_first.hunger` | — (samo mačka) | Your cat is hungry, but first clean up the mess and carry it to the scratching post. Then you can feed it. | Tvoja muca je lačna, a najprej počisti nered in jo odnesi na praskalnik. Potem jo lahko nahraniš. |
| `push.cat.clean_and_scratcher_first.thirst` | — (samo mačka) | Your cat is thirsty, but first clean up the mess and carry it to the scratching post. Then you can give it water. | Tvoja muca je žejna, a najprej počisti nered in jo odnesi na praskalnik. Potem ji lahko daš vodo. |
| `push.cat.scratcher.soft` | — (samo mačka) | Your cat has scratched the sofa. Carry it to the scratching post and praise it. | Tvoja muca je opraskala kavč. Odnesi jo na praskalnik in jo pohvali. |
| `push.cat.scratcher.critical` | — (samo mačka) | Your cat scratched the sofa! Carry it to the scratching post and praise it as soon as you can, or it will get sick. | Muca je opraskala kavč! Čim prej jo odnesi na praskalnik in jo pohvali, sicer bo zbolela. |
| `push.cat.clean_and_scratcher.soft` | — (samo mačka) | Your cat is waiting: clean up the mess, then carry it to the scratching post and praise it. | Tvoja muca te čaka: počisti nered, nato jo odnesi na praskalnik in jo pohvali. |
| `push.cat.clean_and_scratcher.critical` | — (samo mačka) | Clean up the mess and carry your cat to the scratching post as soon as you can, or it will get sick. | Čim prej počisti nered in muco odnesi na praskalnik, sicer bo zbolela. |
| `push.cat.play_reminder` | — (samo mačka) | Your cat hasn’t played today and is waiting for the feather wand. Shall we play? | Tvoja muca se danes še ni igrala in čaka na palico s peresom. Se greva igrat? |
| `push.cat.litter_reminder` | — (samo mačka) | Your cat has used the litter tray. Scoop it soon, before it starts to smell. | Tvoja muca je uporabila pesek. Počisti ga čim prej, preden začne smrdeti. |
| `push.cat.parent_alarm` | Your child hasn’t looked after the dog today. | Your child hasn’t looked after the cat today. | Tvoj otrok danes ni poskrbel za muco. |
| `push.cat.parent_alarm_detail.hunger` | The dog has had no food for over an hour. | The cat has had no food for over an hour. | Muca je že več kot uro brez hrane. |
| `push.cat.parent_alarm_detail.thirst` | The dog has had no water for over an hour. | The cat has had no water for over an hour. | Muca je že več kot uro brez vode. |
| `push.cat.parent_alarm_detail.hygiene` | A mess has not been cleaned up for over an hour. | A mess has not been taken care of for over an hour. | Za nered že več kot uro ni nihče poskrbel. |
| `push.cat.illness.child.hygiene` | Your dog lived in a mess for too long and got sick. It will stay at the vet for 12 hours of observation. | Your cat lived in a mess for too long and got sick. It will stay at the vet for 12 hours of observation. | Muca je predolgo živela v neredu in je zbolela. 12 ur bo na opazovanju pri veterinarju. |
| `push.cat.illness.child.other` | Your dog got sick. It will stay at the vet for 12 hours of observation. | Your cat got sick. It will stay at the vet for 12 hours of observation. | Muca je zbolela. 12 ur bo na opazovanju pri veterinarju. |
| `push.cat.illness.parent.hygiene` | The dog got sick because a mess wasn’t cleaned up. It will stay at the vet for 12 hours of observation. | The cat got sick because a mess wasn’t taken care of. It will stay at the vet for 12 hours of observation. | Muca je zbolela, ker za nered ni nihče poskrbel. 12 ur bo na opazovanju pri veterinarju. |
| `push.cat.illness.parent.other` | The dog got sick. It will stay at the vet for 12 hours of observation. | The cat got sick. It will stay at the vet for 12 hours of observation. | Muca je zbolela. 12 ur bo na opazovanju pri veterinarju. |
| `push.cat.game_over.child` | Your dog has gone to a shelter because nobody looked after it for too long. Talk to your parents. | Your cat has gone to a shelter because nobody looked after it for too long. Talk to your parents. | Muca je odšla v zavetišče, ker zanjo predolgo ni nihče poskrbel. Pogovori se s starši. |
| `push.cat.game_over.parent` | The dog has gone to a shelter because it went 24 hours without essential care. Choose how to continue in the app. | The cat has gone to a shelter because it went 24 hours without essential care. Choose how to continue in the app. | Muca je odšla v zavetišče, ker 24 ur ni dobila nujne skrbi. V aplikaciji izberite, kako naprej. |
| `push.cat.payment_required.child` | The game is waiting for your parent. Your dog is safe and resting. | The game is waiting for your parent. Your cat is safe and resting. | Igra počaka na starša. Tvoja muca je na varnem in počiva. |
| `push.cat.payment_required.parent` | The free trial has ended. The dog is waiting safely until you unlock the 12-week challenge in the app. | The free trial has ended. The cat is waiting safely until you unlock the 12-week challenge in the app. | Brezplačni preizkus je končan. Muca varno čaka, dokler v aplikaciji ne odklenete 12-tedenskega izziva. |
| `push.cat.payment_required.parent_no_trial` | The dog is waiting safely until you unlock the 12-week challenge in the app. | The cat is waiting safely until you unlock the 12-week challenge in the app. | Muca varno čaka, dokler v aplikaciji ne odklenete 12-tedenskega izziva. |
| `account.export.about_pets` | Family data export from the PetPrep app (GDPR Art. 15 and 20). Times are in UTC (ISO 8601), dates (local_date) in the family’s time zone. Links to the dogs’ images and videos are valid for a limited time (media[].expires_at, growth[] until growth_expires_at). | Family data export from the PetPrep app (GDPR Art. 15 and 20). Times are in UTC (ISO 8601), dates (local_date) in the family’s time zone. Links to the pets’ images and videos are valid for a limited time (media[].expires_at, growth[] until growth_expires_at). | Izvoz podatkov družine iz aplikacije PetPrep (GDPR čl. 15 in 20). Časi so v UTC (ISO 8601), datumi (local_date) v časovnem pasu družine. Povezave do slik in videov ljubljenčkov veljajo omejen čas (media[].expires_at, growth[] do growth_expires_at). |

**Samo pes (mačka jih nikoli ne dobi, zato brez mačje različice):** `push.walk_reminder` (sprehod — muca ima `play_reminder`), `push.tidy.*` in `push.clean_and_tidy.*` (grizenje — muca ima praskanje), `push.illness.*.walk` (muca zaradi zamujene igre ne zboli, CAT_SPEC Q2). Brez živali v besedilu (skupno): `push.title`, `push.trial_ending`.

**Besedila sporočil API (samo angleško, za razvijalce, aplikacija jih ne prikaže):** ob mački »This cat is on the free plan.« / »This cat's challenge is already unlocked.« / »This cat is no longer in play.«; izbris računa: »This deletes a pet whose …«, ko je med psi muca. Admin (Filament): »Marks this cat's 12-week challenge …«.

## Odprta vprašanja za Davida

1. **Mucek / muca:** CAT_SPEC §9 pravi »mucek« za mladiča. Pasja obvestila mladička ne ločijo (»kuža« za vse starosti), zato tudi mačja uporabljajo »muca« za vse starosti. Želiš ločena besedila za mucka (»Tvoj mucek je lačen«, »bo zbolel« — moški spol, ~30 dodatnih vrstic na jezik)?
2. **Angleški zaimek:** *it* (kot pri psu) ali *she / her* (kot slovenska »muca«)?
3. **»Tvoj otrok …« za starša:** pasja besedila staršu rečejo »Tvoj otrok« (PRODUCT_SPEC §6), drugje pa staršu vikamo. Mačja so zaradi doslednosti enaka; če želiš »Vaš otrok«, bi spremenili oboje (pas in mačka) — to bi bila sprememba pasjih besedil.
4. **Enaka napaka pri psu (najdeno ob QA m3):** ko je pri psu odprto samo grizenje, obvestilo o hrani / vodi reče »najprej je treba počistiti nered«, čeprav grizenje razreši »Pospravi in daj igračo« (čiščenje ga ne). Ker se pasja besedila v tej nalogi ne smejo spremeniti, je ostalo, kot je. Predlog: pasja različica »najprej pospravi in mu daj igračo« (`tidy_first`) v ločenem PR.
