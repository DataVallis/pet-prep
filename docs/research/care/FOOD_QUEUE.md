# Food queue — »Ali lahko pes / muca je …?« (M6-K)

David 2026-10-31: **every food we can find sources for**, not a sample. Each line = one file per species
(`foods/dog/<slug>.json`, `foods/cat/<slug>.json`). A food is skipped for a species only when no tier A/B
source covers it for that species (then the line gets `— no source <species> <date>`).

Waves: **W1** (now, 4 agents) · **W2+** later batches / nightly runs, one batch per run, same runbook as
breeds (write → independent verification → PR → merge on green CI).

Status per line: `[ ]` todo · `[d]` dog done · `[c]` cat done · `[x]` both · `[-]` skipped for every species; one item per line once processed. A "no source" note = skipped for that species (retry only with a new A/B source).

## W1 — the classics (toxic / most searched) — done 2026-10-31 (M6-K0)
- [x] chocolate
- [x] grapes
- [x] raisins
- [x] onion
- [x] garlic
- [x] leek
- [x] chives
- [d] xylitol — no source cat 2026-10-31
- [x] macadamia-nuts
- [x] alcohol
- [x] raw-yeast-dough
- [x] coffee
- [x] tea
- [x] energy-drinks
- [x] avocado
- [x] cherries
- [x] peaches
- [x] plums
- [x] apricots
- [x] cooked-bones
- [x] raw-bones
- [d] corn-cob — no source cat 2026-10-31
- [x] fatty-meat
- [d] bacon — no source cat 2026-10-31
- [x] ham
- [x] salt
- [d] salty-snacks — no source cat 2026-10-31
- [d] chips — no source cat 2026-10-31
- [x] milk
- [x] cheese
- [x] yogurt
- [x] ice-cream
- [x] butter
- [x] almonds
- [x] walnuts
- [d] peanuts — no source cat 2026-10-31
- [x] peanut-butter
- [d] cashews — no source cat 2026-10-31
- [d] pistachios — no source cat 2026-10-31
- [d] pecans — no source cat 2026-10-31
- [d] hazelnuts — no source cat 2026-10-31
- [x] raw-eggs
- [x] cooked-eggs
- [x] raw-meat
- [x] raw-fish
- [x] tuna
- [x] salmon
- [x] sardines
- [x] cooked-chicken
- [x] turkey
- [x] beef
- [x] pork
- [x] lamb
- [x] liver
- [d] shrimp — no source cat 2026-10-31
- [-] crab — no source dog 2026-10-31 — no source cat 2026-10-31

## W2 — fruit
- [x] apples
- [x] apple-seeds
- [x] bananas
- [x] blueberries
- [x] strawberries
- [d] raspberries — no source cat 2026-11-01
- [d] blackberries — no source cat 2026-11-01
- [x] cranberries
- [x] watermelon
- [x] cantaloupe
- [ ] honeydew · pineapple · mango · papaya · kiwi · oranges · mandarins · lemons
- [ ] limes · grapefruit · pears · figs · dates · pomegranate · coconut · coconut-oil · lychee · persimmon
- [ ] dried-fruit · fruit-pits · rhubarb · star-fruit · passion-fruit · olives

## W3 — vegetables and plants we eat
- [ ] carrots · cucumber · green-beans · peas · broccoli · cauliflower · brussels-sprouts · cabbage · kale
- [ ] spinach · lettuce · celery · zucchini · pumpkin · sweet-potato · potatoes-raw · potatoes-cooked
- [ ] tomatoes · tomato-plant · peppers · chili-peppers · mushrooms-store · wild-mushrooms · asparagus
- [ ] beetroot · corn · edamame · radish · eggplant · artichoke · seaweed · herbs-parsley · basil · mint
- [ ] catnip (cat) · cat-grass (cat)

## W4 — grains, kitchen, sweets
- [ ] bread · rice · pasta · oatmeal · quinoa · popcorn · cereal · crackers · pizza · french-fries
- [ ] sugar · candy · honey · jam · cookies · cake · marshmallows · gum · nutmeg · cinnamon · ginger
- [ ] turmeric · black-pepper · vanilla · cocoa-powder · baking-soda · vinegar · soy-sauce · ketchup
- [ ] mayonnaise · mustard · gravy · broth · hot-dogs · sausages · deli-meat · fish-sticks · sushi
- [ ] tofu · beans · lentils · chickpeas · hummus · nut-butters · seeds-sunflower · chia-seeds · flaxseed

## W5 — drinks and other
- [ ] water · cow-milk-kittens (cat) · lactose-free-milk · goat-milk · kefir · juice · soda · coconut-water
- [ ] bone-broth · sports-drinks · fish-oil · vitamins-human (→ hazards, not food) · pet-treats-for-other-species
  (dog food for cats, cat food for dogs)

Hazards that are not food (lilies, poinsettia, medicines, permethrin, antifreeze …) → steber B, separate queue.
