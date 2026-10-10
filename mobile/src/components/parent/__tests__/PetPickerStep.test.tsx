/**
 * M5-R06-02: species-aware picker — species step, server breed list with search,
 * badges, locked breeds, summary.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import PetPickerStep from '@/components/parent/PetPickerStep';
import { i18n } from '@/i18n';
import {
  FALLBACK_CATALOGUE,
  INITIAL_PICKER_CHOICE,
  PICKER_STRINGS as PICKER,
  pickerText,
  readBreedCatalogue,
  type BreedCatalogue,
} from '@/modules/petProfile/picker';

function entry(breed: string, species: string, premium: boolean, sort_order: number, keywords: string[]) {
  return {
    breed,
    slug: breed,
    species,
    premium,
    free_plan_allowed: !premium,
    challenge_allowed: premium,
    label_key: `breeds.${breed}`,
    search_keywords: keywords,
    sort_order,
  };
}

const BOTH = readBreedCatalogue({
  species: ['dog', 'cat'],
  breeds: [
    entry('mutt', 'dog', false, 0, ['mešanček', 'mesancek']),
    entry('border_collie', 'dog', true, 10, ['border collie', 'koli']),
    entry('domestic_cat', 'cat', false, 0, ['domača mačka', 'mešanka']),
    entry('maine_coon', 'cat', true, 10, ['maine coon', 'mejnkun']),
  ],
}, ['dog', 'cat']) as BreedCatalogue; // a build with the cat UI (CAT_UI_READY on)

/** The server's dog catalogue since M5-R10 (two paid dogs with suitability tags). */
const DOGS_R10 = readBreedCatalogue({
  species: ['dog'],
  breeds: [
    { ...entry('mutt', 'dog', false, 0, ['mešanček']), suitability: { suits: [], consider: [] } },
    {
      ...entry('labrador_retriever', 'dog', true, 20, ['labradorec', 'prinašalec']),
      suitability: {
        suits: ['active_family', 'family_pet', 'large_home', 'other_pets', 'future_tag'],
        consider: ['sheds', 'long_daily_exercise', 'food_motivated_weight'],
      },
    },
    {
      ...entry('border_collie', 'dog', true, 10, ['koli']),
      suitability: { suits: ['active_family'], consider: ['long_daily_exercise', 'needs_mental_stimulation', 'may_herd_children', 'chews_when_bored'] },
    },
  ],
}) as BreedCatalogue;

function renderPicker(catalogue: BreedCatalogue | null, onConfirm = jest.fn(), onBack = jest.fn()) {
  render(
    <PetPickerStep childName="Maja" catalogue={catalogue} initial={INITIAL_PICKER_CHOICE} onConfirm={onConfirm} onBack={onBack} />,
  );
  return { onConfirm, onBack };
}

describe('PetPickerStep', () => {
  it('only one species (cats hidden): no species step — straight to the dog picker, wording unchanged', () => {
    renderPicker(FALLBACK_CATALOGUE);
    expect(screen.queryByTestId('species-picker')).toBeNull();
    expect(screen.getByText(PICKER.title('Maja'))).toBeTruthy();
    expect(screen.queryByTestId('picker-change-species')).toBeNull();
    expect(screen.queryByTestId('breed-option-domestic_cat')).toBeNull();
  });

  it('two species: big tiles first, nothing preselected; the cat leads to the cat breeds', () => {
    renderPicker(BOTH);
    expect(screen.getByTestId('species-picker')).toBeTruthy();
    expect(screen.getByText(PICKER.speciesTitle('Maja'))).toBeTruthy();
    expect(screen.getByTestId('species-option-dog').props.accessibilityState).toEqual({ checked: false });
    expect(screen.getByTestId('species-option-cat').props.accessibilityState).toEqual({ checked: false });
    expect(screen.queryByTestId('dog-picker-confirm')).toBeNull();

    fireEvent.press(screen.getByTestId('species-option-cat'));
    expect(screen.getByText(PICKER.cat.title('Maja'))).toBeTruthy();
    expect(screen.getByTestId('breed-option-domestic_cat')).toBeTruthy();
    expect(screen.getByTestId('breed-option-maine_coon')).toBeTruthy();
    expect(screen.queryByTestId('breed-option-mutt')).toBeNull();
    // Cat wording: kitten ages, draft hints from the catalogue breed.
    expect(screen.getByText('Mucek')).toBeTruthy();
    expect(screen.getByText('Kratka dlaka · brezplačna')).toBeTruthy();
    expect(screen.getByText('Dolga dlaka · česanje 3× na teden')).toBeTruthy();

    // "Spremeni vrsto" goes back to the tiles, the cat stays marked.
    fireEvent.press(screen.getByTestId('picker-change-species'));
    expect(screen.getByTestId('species-option-cat').props.accessibilityState).toEqual({ checked: true });
  });

  it('free breed first with the "Brezplačno" badge, paid with "Izziv"; a locked breed is greyed and explained', () => {
    renderPicker(BOTH);
    fireEvent.press(screen.getByTestId('species-option-cat'));
    fireEvent.press(screen.getByTestId('plan-option-free'));
    expect(screen.getByText(PICKER.badgeFree)).toBeTruthy();
    expect(screen.getByText(PICKER.badgeChallenge)).toBeTruthy();

    const coon = screen.getByTestId('breed-option-maine_coon');
    expect(coon.props.accessibilityState).toEqual({ checked: false, disabled: true });
    expect(coon.props.accessibilityLabel).toBe(PICKER.lockedA11y('Maine Coon'));
    fireEvent.press(coon);
    expect(screen.getByTestId('breed-locked-note')).toHaveTextContent(pickerText('cat').breedFreeNote);
    expect(screen.getByTestId('breed-option-domestic_cat').props.accessibilityState).toEqual({ checked: true, disabled: false });

    // Challenge: Maine Coon selected, the domestic cat locked (M5-F03).
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    expect(screen.getByTestId('breed-option-maine_coon').props.accessibilityState).toEqual({ checked: true, disabled: false });
    expect(screen.getByTestId('breed-option-domestic_cat').props.accessibilityState.disabled).toBe(true);
  });

  it('search filters the server list (diacritics, synonyms) and says when nothing matches', () => {
    renderPicker(BOTH);
    fireEvent.press(screen.getByTestId('species-option-cat'));
    fireEvent.changeText(screen.getByTestId('breed-search'), 'mejnkun');
    expect(screen.getByTestId('breed-option-maine_coon')).toBeTruthy();
    expect(screen.queryByTestId('breed-option-domestic_cat')).toBeNull();

    fireEvent.changeText(screen.getByTestId('breed-search'), 'DOMACA');
    expect(screen.getByTestId('breed-option-domestic_cat')).toBeTruthy();
    expect(screen.queryByTestId('breed-option-maine_coon')).toBeNull();

    fireEvent.changeText(screen.getByTestId('breed-search'), 'sfinga');
    expect(screen.getByTestId('breed-search-empty')).toHaveTextContent(PICKER.searchEmpty('sfinga'));
  });

  it('dogs: "mesancek" finds Mešanček', () => {
    renderPicker(FALLBACK_CATALOGUE);
    fireEvent.changeText(screen.getByTestId('breed-search'), 'mesancek');
    expect(screen.getByTestId('breed-option-mutt')).toBeTruthy();
    expect(screen.queryByTestId('breed-option-border_collie')).toBeNull();
  });

  it('summary before the PIN shows species, breed, origin, age and plan; confirm sends the species', () => {
    const { onConfirm } = renderPicker(BOTH);
    fireEvent.press(screen.getByTestId('species-option-cat'));
    fireEvent.press(screen.getByTestId('plan-option-free'));
    fireEvent.press(screen.getByTestId('origin-option-adopted'));
    expect(screen.queryByTestId('picker-summary')).toBeNull();
    fireEvent.press(screen.getByTestId('age-option-puppy'));

    const summary = screen.getByTestId('picker-summary');
    expect(summary).toHaveTextContent(/Mačka/);
    expect(summary).toHaveTextContent(/Domača mačka/);
    expect(summary).toHaveTextContent(/Posvojena/);
    expect(summary).toHaveTextContent(/Mucek/);
    expect(summary).toHaveTextContent(/Brezplačno/);

    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'cat', breed: 'domestic_cat', origin: 'adopted', age_stage: 'puppy', plan: 'free' },
      expect.objectContaining({ species: 'cat' }),
    );
  });

  it('M5-R10: the Labrador is listed after the collie with the "Izziv" badge and its suitability chips', () => {
    renderPicker(DOGS_R10);
    const lab = screen.getByTestId('breed-option-labrador_retriever');
    expect(lab).toHaveTextContent(/Labradorec/);
    expect(lab).toHaveTextContent(new RegExp(PICKER.badgeChallenge));
    expect(lab).toHaveTextContent(/Del 12-tedenskega izziva\./);

    const suits = screen.getByTestId('breed-suitability-labrador_retriever-suits');
    expect(suits).toHaveTextContent('Primerno za:aktivno družinodružinsko življenjeveliko hišo z vrtomdom z drugimi ljubljenčki');
    const consider = screen.getByTestId('breed-suitability-labrador_retriever-consider');
    expect(consider).toHaveTextContent('Upoštevajte:izpada mu dlakavsak dan potrebuje veliko gibanjarad je — pazite na težo');
    // The unknown tag from a newer server is never shown.
    expect(screen.queryByText(/future_tag/)).toBeNull();
    // Screen readers get the same tags as one sentence.
    expect(lab.props.accessibilityHint).toBe(
      'Primerno za: aktivno družino, družinsko življenje, veliko hišo z vrtom, dom z drugimi ljubljenčki. Upoštevajte: izpada mu dlaka, vsak dan potrebuje veliko gibanja, rad je — pazite na težo.',
    );
    // The collie shows its own tags; the mutt has none (no sourced tags).
    expect(screen.getByTestId('breed-suitability-border_collie-consider')).toHaveTextContent(/pri igri lahko »pase« otroke/);
    expect(screen.queryByTestId('breed-suitability-mutt')).toBeNull();
    expect(screen.getByTestId('breed-option-mutt').props.accessibilityHint).toBeUndefined();

    // Order: free first, then paid by sort_order (collie 10, Labrador 20).
    const order = screen.getAllByTestId(/^breed-option-/).map((n) => n.props.testID);
    expect(order).toEqual(['breed-option-mutt', 'breed-option-border_collie', 'breed-option-labrador_retriever']);
  });

  it('M5-R10: free plan locks the Labrador with a breed-neutral note; the challenge picks it and sends it', () => {
    const { onConfirm } = renderPicker(DOGS_R10);
    fireEvent.press(screen.getByTestId('plan-option-free'));
    const lab = screen.getByTestId('breed-option-labrador_retriever');
    expect(lab.props.accessibilityState).toEqual({ checked: false, disabled: true });
    expect(lab.props.accessibilityLabel).toBe(PICKER.lockedA11y('Labradorec'));
    fireEvent.press(lab);
    expect(screen.getByTestId('breed-locked-note')).toHaveTextContent(
      'V brezplačnem načrtu je pes vedno mešanček. Plačljive pasme so del 12-tedenskega izziva.',
    );

    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-labrador_retriever'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    fireEvent.press(screen.getByTestId('age-option-senior'));
    // Age hints follow the chosen breed.
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/6\.800 korakov/);
    expect(screen.getByTestId('picker-summary')).toHaveTextContent(/Labradorec/);
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'labrador_retriever', origin: 'bought', age_stage: 'senior', plan: 'challenge' },
      expect.objectContaining({ breed: 'labrador_retriever' }),
    );
  });

  it('M5-R10: search finds the Labrador by its Slovenian synonym; the fallback lists it too', () => {
    renderPicker(FALLBACK_CATALOGUE);
    expect(screen.getByTestId('breed-suitability-labrador_retriever')).toBeTruthy();
    fireEvent.changeText(screen.getByTestId('breed-search'), 'prinasalec');
    expect(screen.getByTestId('breed-option-labrador_retriever')).toBeTruthy();
    expect(screen.queryByTestId('breed-option-border_collie')).toBeNull();
  });

  it('M5-R10-02: the Golden Retriever (fallback) shows its chips incl. brushing, is found as "zlati" and quotes 12.000 / 9.000 steps', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const golden = screen.getByTestId('breed-option-golden_retriever');
    expect(golden).toHaveTextContent(/Zlati prinašalec/);
    expect(golden).toHaveTextContent(new RegExp(PICKER.badgeChallenge));
    expect(screen.getByTestId('breed-suitability-golden_retriever-suits')).toHaveTextContent(
      'Primerno za:aktivno družinodružinsko življenjedružino z otrokizačetnikeveliko hišo z vrtomdom z drugimi ljubljenčki',
    );
    expect(screen.getByTestId('breed-suitability-golden_retriever-consider')).toHaveTextContent(
      'Upoštevajte:vsak dan potrebuje veliko gibanjaizpada mu dlakarad je — pazite na težopotrebuje česanje večkrat na teden',
    );
    // Order: free first, then paid by sort_order (collie 10, Labrador 20, Golden 30, French Bulldog 40, German Shepherd 50, Cavalier 60, Beagle 70, Standard Poodle 80, Dachshund 90).
    expect(screen.getAllByTestId(/^breed-option-/).map((n) => n.props.testID)).toEqual([
      'breed-option-mutt',
      'breed-option-border_collie',
      'breed-option-labrador_retriever',
      'breed-option-golden_retriever',
      'breed-option-french_bulldog',
      'breed-option-german_shepherd',
      'breed-option-cavalier_king_charles_spaniel',
      'breed-option-beagle',
      'breed-option-standard_poodle',
      'breed-option-dachshund',
    ]);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'zlati');
    expect(screen.queryByTestId('breed-option-labrador_retriever')).toBeNull();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-golden_retriever'));
    fireEvent.press(screen.getByTestId('origin-option-adopted'));
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/12\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/9\.000 korakov/);
    fireEvent.press(screen.getByTestId('age-option-adult'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'golden_retriever', origin: 'adopted', age_stage: 'adult', plan: 'challenge' },
      expect.objectContaining({ breed: 'golden_retriever' }),
    );
  });

  it('M5-R10-02: the brushing chip in English', async () => {
    await i18n.changeLanguage('en');
    try {
      renderPicker(FALLBACK_CATALOGUE);
      expect(screen.getByTestId('breed-suitability-golden_retriever-consider')).toHaveTextContent(/needs brushing several times a week/);
      expect(screen.getByTestId('breed-suitability-golden_retriever-suits')).toHaveTextContent(/families with childrenfirst-time owners/);
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-03: the French Bulldog (fallback) shows the flat / breathing chips, is found as "buldog" and quotes 6.000 / 4.500 steps', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const frenchie = screen.getByTestId('breed-option-french_bulldog');
    expect(frenchie).toHaveTextContent(/Francoski buldog/);
    expect(frenchie).toHaveTextContent(new RegExp(PICKER.badgeChallenge));
    expect(screen.getByTestId('breed-suitability-french_bulldog-suits')).toHaveTextContent(
      'Primerno za:življenje v stanovanjudružinsko življenjedružino z otroki',
    );
    expect(screen.getByTestId('breed-suitability-french_bulldog-consider')).toHaveTextContent(
      'Upoštevajte:kratek gobček — težave z dihanjem in vročino',
    );

    fireEvent.changeText(screen.getByTestId('breed-search'), 'buldog');
    expect(screen.queryByTestId('breed-option-golden_retriever')).toBeNull();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-french_bulldog'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'french_bulldog', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'french_bulldog' }),
    );
  });

  it('M5-R10-04: the German Shepherd (fallback) shows the hips chip, is found as "ovčar" and quotes 12.000 / 9.000 steps', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const shepherd = screen.getByTestId('breed-option-german_shepherd');
    expect(shepherd).toHaveTextContent(/Nemški ovčar/);
    expect(shepherd).toHaveTextContent(new RegExp(PICKER.badgeChallenge));
    expect(screen.getByTestId('breed-suitability-german_shepherd-suits')).toHaveTextContent(
      'Primerno za:aktivno družinodružinsko življenjeveliko hišo z vrtom',
    );
    expect(screen.getByTestId('breed-suitability-german_shepherd-consider')).toHaveTextContent(
      /kolki in zadnje noge — preverite zdravje sklepov/,
    );

    fireEvent.changeText(screen.getByTestId('breed-search'), 'ovčar');
    expect(screen.queryByTestId('breed-option-french_bulldog')).toBeNull();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-german_shepherd'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 12\.000 pri 12 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/12\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/9\.000 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'german_shepherd', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'german_shepherd' }),
    );
  });

  it('M5-R10-04: the hips chip in English, without numbers', async () => {
    await i18n.changeLanguage('en');
    try {
      renderPicker(FALLBACK_CATALOGUE);
      const consider = screen.getByTestId('breed-suitability-german_shepherd-consider');
      expect(consider).toHaveTextContent(/hips and hind legs — check joint health/);
      expect(consider).not.toHaveTextContent(/\d|%/);
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-05: the Cavalier (fallback) shows the heart / spine chip, is found as "kavalir" and quotes 6.000 / 4.500 steps', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const cavalier = screen.getByTestId('breed-option-cavalier_king_charles_spaniel');
    expect(cavalier).toHaveTextContent(/Kavalir King Charles španjel/);
    expect(cavalier).toHaveTextContent(new RegExp(PICKER.badgeChallenge));
    expect(screen.getByTestId('breed-suitability-cavalier_king_charles_spaniel-consider')).toHaveTextContent(
      /srce in hrbtenjača — preverite zdravstvene teste/,
    );

    fireEvent.changeText(screen.getByTestId('breed-search'), 'kavalir');
    expect(screen.queryByTestId('breed-option-german_shepherd')).toBeNull();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-cavalier_king_charles_spaniel'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'cavalier_king_charles_spaniel', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'cavalier_king_charles_spaniel' }),
    );
  });

  it('M5-R10-06: the Beagle (fallback) shows its chips, is found as "bigl" and quotes 6.000 / 4.500 steps', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const beagle = screen.getByTestId('breed-option-beagle');
    expect(beagle).toHaveTextContent(/Bigl/);
    expect(beagle).toHaveTextContent(new RegExp(PICKER.badgeChallenge));
    expect(screen.getByTestId('breed-suitability-beagle-suits')).toHaveTextContent(/družinsko življenje/);
    expect(screen.getByTestId('breed-suitability-beagle-consider')).toHaveTextContent(/izpada mu dlakako se dolgočasi, grize stvari/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'bigl');
    expect(screen.queryByTestId('breed-option-cavalier_king_charles_spaniel')).toBeNull();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-beagle'));
    fireEvent.press(screen.getByTestId('origin-option-adopted'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-senior'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'beagle', origin: 'adopted', age_stage: 'senior', plan: 'challenge' },
      expect.objectContaining({ breed: 'beagle' }),
    );
  });

  it('M5-R10-06: the Beagle chips in English, without numbers', async () => {
    await i18n.changeLanguage('en');
    try {
      renderPicker(FALLBACK_CATALOGUE);
      const consider = screen.getByTestId('breed-suitability-beagle-consider');
      expect(consider).toHaveTextContent(/sheds/);
      expect(consider).toHaveTextContent(/chews things when bored/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-beagle')).toHaveTextContent(/Beagle/);
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-07: the Standard Poodle (fallback) shows its chips, is found as "koder" and "pudelj" and quotes 6.000 / 4.500 steps', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const poodle = screen.getByTestId('breed-option-standard_poodle');
    expect(poodle).toHaveTextContent(/Veliki koder/);
    expect(poodle).toHaveTextContent(new RegExp(PICKER.badgeChallenge));
    expect(screen.getByTestId('breed-suitability-standard_poodle-suits')).toHaveTextContent(
      /družino z otrokiveliko hišo z vrtomdom z drugimi ljubljenčkidom, kjer želite manj dlak/,
    );
    expect(screen.getByTestId('breed-suitability-standard_poodle-consider')).toHaveTextContent(/potrebuje česanje večkrat na teden/);

    fireEvent.changeText(screen.getByTestId('breed-search'), 'pudelj');
    expect(screen.getByTestId('breed-option-standard_poodle')).toBeTruthy();
    fireEvent.changeText(screen.getByTestId('breed-search'), 'koder');
    expect(screen.queryByTestId('breed-option-beagle')).toBeNull();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-standard_poodle'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'standard_poodle', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'standard_poodle' }),
    );
  });

  it('M5-R10-07: the Standard Poodle chips in English, without numbers and never "hypoallergenic"', async () => {
    await i18n.changeLanguage('en');
    try {
      renderPicker(FALLBACK_CATALOGUE);
      const suits = screen.getByTestId('breed-suitability-standard_poodle-suits');
      expect(suits).toHaveTextContent(/homes that prefer less shedding/);
      expect(suits).not.toHaveTextContent(/hypoallergenic|\d|%/i);
      expect(screen.getByTestId('breed-suitability-standard_poodle-consider')).toHaveTextContent(/needs brushing several times a week/);
      expect(screen.getByTestId('breed-option-standard_poodle')).toHaveTextContent(/Poodle \(Standard\)/);
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-08: the Dachshund (fallback) shows its chips, is found as "jazbečar" and quotes 6.000 / 4.500 steps', () => {
    const { onConfirm } = renderPicker(FALLBACK_CATALOGUE);
    const dachshund = screen.getByTestId('breed-option-dachshund');
    expect(dachshund).toHaveTextContent(/Jazbečar/);
    expect(dachshund).toHaveTextContent(new RegExp(PICKER.badgeChallenge));
    expect(screen.getByTestId('breed-suitability-dachshund-suits')).toHaveTextContent(/družino z otroki/);
    expect(screen.getByTestId('breed-suitability-dachshund-consider')).toHaveTextContent(
      /dolg hrbet — težave s hrbtenico, brez skakanjaizpada mu dlakapotrebuje miselne izzive/,
    );

    fireEvent.changeText(screen.getByTestId('breed-search'), 'teckel');
    expect(screen.getByTestId('breed-option-dachshund')).toBeTruthy();
    fireEvent.changeText(screen.getByTestId('breed-search'), 'jazbecar');
    expect(screen.queryByTestId('breed-option-beagle')).toBeNull();
    fireEvent.press(screen.getByTestId('plan-option-challenge'));
    fireEvent.press(screen.getByTestId('breed-option-dachshund'));
    fireEvent.press(screen.getByTestId('origin-option-bought'));
    expect(screen.getByTestId('age-option-puppy')).toHaveTextContent(/do 6\.000 pri 6 mesecih/);
    expect(screen.getByTestId('age-option-adult')).toHaveTextContent(/6\.000 korakov/);
    expect(screen.getByTestId('age-option-senior')).toHaveTextContent(/4\.500 korakov/);
    fireEvent.press(screen.getByTestId('age-option-puppy'));
    fireEvent.press(screen.getByTestId('dog-picker-confirm'));
    expect(onConfirm).toHaveBeenCalledWith(
      { species: 'dog', breed: 'dachshund', origin: 'bought', age_stage: 'puppy', plan: 'challenge' },
      expect.objectContaining({ breed: 'dachshund' }),
    );
  });

  it('M5-R10-08: the Dachshund back chip in English, without numbers', async () => {
    await i18n.changeLanguage('en');
    try {
      renderPicker(FALLBACK_CATALOGUE);
      const consider = screen.getByTestId('breed-suitability-dachshund-consider');
      expect(consider).toHaveTextContent(/long back — spine problems, avoid jumping/);
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-option-dachshund')).toHaveTextContent(/Dachshund/);
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-05: the heart / spine chip in English, without numbers', async () => {
    await i18n.changeLanguage('en');
    try {
      renderPicker(FALLBACK_CATALOGUE);
      const consider = screen.getByTestId('breed-suitability-cavalier_king_charles_spaniel-consider');
      expect(consider).toHaveTextContent(/heart and spine — check health tests/);
      expect(consider).not.toHaveTextContent(/\d|%/);
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10-03: the breathing chip in English, without numbers', async () => {
    await i18n.changeLanguage('en');
    try {
      renderPicker(FALLBACK_CATALOGUE);
      const consider = screen.getByTestId('breed-suitability-french_bulldog-consider');
      expect(consider).toHaveTextContent('Keep in mind:flat face — breathing and heat problems');
      expect(consider).not.toHaveTextContent(/\d|%/);
      expect(screen.getByTestId('breed-suitability-french_bulldog-suits')).toHaveTextContent(/apartment livingfamily lifefamilies with children/);
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('M5-R10: suitability chips in English', async () => {
    await i18n.changeLanguage('en');
    try {
      renderPicker(DOGS_R10);
      expect(screen.getByTestId('breed-suitability-labrador_retriever-suits')).toHaveTextContent(
        'Good for:an active familyfamily lifea large house and gardenhomes with other pets',
      );
      expect(screen.getByTestId('breed-suitability-labrador_retriever-consider')).toHaveTextContent(
        'Keep in mind:shedsneeds lots of exercise every dayloves food — watch the weight',
      );
    } finally {
      await i18n.changeLanguage('sl');
    }
  });

  it('while the catalogue loads: a spinner, and "Nazaj" still works', () => {
    const { onBack } = renderPicker(null);
    expect(screen.getByTestId('pet-picker-loading')).toBeTruthy();
    expect(screen.queryByTestId('dog-picker-confirm')).toBeNull();
    fireEvent.press(screen.getByTestId('dog-picker-back'));
    expect(onBack).toHaveBeenCalledWith(INITIAL_PICKER_CHOICE);
  });
});
