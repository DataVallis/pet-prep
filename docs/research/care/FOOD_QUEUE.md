# Food queue — »Ali lahko pes / muca je …?« (M6-K)

David 2026-10-31: **every food we can find sources for**, not a sample. Each line = one file per species
(`foods/dog/<slug>.json`, `foods/cat/<slug>.json`). A food is skipped for a species only when no tier A/B
source covers it for that species (then the line gets `— no source <species> <date>`).

Waves: **W1** (now, 4 agents) · **W2+** later batches / nightly runs, one batch per run, same runbook as
breeds (write → independent verification → PR → merge on green CI).

Status per line: `[ ]` todo · `[d]` dog done · `[c]` cat done · `[x]` both.

## W1 — the classics (toxic / most searched)
- [ ] chocolate · grapes · raisins · onion · garlic · leek · chives · xylitol · macadamia-nuts · alcohol
- [ ] raw-yeast-dough · coffee · tea · energy-drinks · avocado · cherries · peaches · plums · apricots
- [ ] cooked-bones · raw-bones · corn-cob · fatty-meat · bacon · ham · salt · salty-snacks · chips
- [ ] milk · cheese · yogurt · ice-cream · butter · almonds · walnuts · peanuts · peanut-butter · cashews
- [ ] pistachios · pecans · hazelnuts · raw-eggs · cooked-eggs · raw-meat · raw-fish · tuna · salmon · sardines
- [ ] cooked-chicken · turkey · beef · pork · lamb · liver · shrimp · crab

## W2 — fruit
- [ ] apples · apple-seeds · bananas · blueberries · strawberries · raspberries · blackberries · cranberries
- [ ] watermelon · cantaloupe · honeydew · pineapple · mango · papaya · kiwi · oranges · mandarins · lemons
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
