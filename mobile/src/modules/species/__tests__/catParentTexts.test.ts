/**
 * M5-R06-08c: parent texts take the species of the pet shown (a family may have a dog and a
 * cat) — never the child's global text species. Dog texts stay exactly as they were.
 */
import { act } from '@testing-library/react-native';

import { i18n, setTextSpecies, t } from '@/i18n';
import { ACCOUNT_STRINGS } from '@/components/parent/AccountCard';
import { PET_CONTROLS_STRINGS } from '@/components/parent/PetControlsCard';
import { CHILD_CARD_STRINGS } from '@/components/parent/ChildOverviewCard';
import { parentBehaviourLines, type PetBehaviour } from '@/modules/behaviour/behaviour';
import { petStatusText } from '@/modules/family/family';
import { activityText, reasonText } from '@/modules/family/scoring';
import { growthViewerItems, type GrowthAlbum } from '@/modules/petMedia/growth';
import { nextStageLine, originLine, stageLine, type PetProfileInfo } from '@/modules/petProfile/petProfile';
import { catCount, petCountText } from '@/modules/species/species';
import { DASHBOARD_STRINGS } from '@/screens/parent/ParentDashboardScreen';

const TZ = 'Europe/Ljubljana';
const initialLanguage = i18n.language;

async function switchLanguage(language: 'en' | 'sl') {
  await act(async () => {
    await i18n.changeLanguage(language);
  });
}

afterEach(async () => {
  setTextSpecies(null);
  await act(async () => {
    await i18n.changeLanguage(initialLanguage);
  });
});

const profile: PetProfileInfo = {
  ageMonths: 3,
  stage: 'puppy',
  origin: 'adopted',
  nextStage: { stage: 'young', fromDate: '2026-11-24' },
  meals: null,
};

function behaviour(kind: 'scratching' | 'chewing'): PetBehaviour {
  return { take_out: null, scene: null, active_events: [{ id: 1, kind, started_at: '2026-10-04T11:30:00+02:00', due_at: '2026-10-04T13:30:00+02:00' }] };
}

describe('Slovenian parent texts per species', () => {
  beforeEach(async () => {
    await switchLanguage('sl');
  });

  it('pet counts: dogs unchanged, cats "muca", both side by side', () => {
    expect(DASHBOARD_STRINGS.familySubtitle(2, 1)).toBe('2 otroka · 1 kuža');
    expect(DASHBOARD_STRINGS.familySubtitle(2, 1, 1)).toBe('2 otroka · 1 muca');
    expect(DASHBOARD_STRINGS.familySubtitle(1, 2, 2)).toBe('1 otrok · 2 muci');
    expect(DASHBOARD_STRINGS.familySubtitle(3, 5, 3)).toBe('3 otroci · 2 kužka · 3 muce');
    expect(petCountText('account:counts.dogs', 5, 5)).toBe('5 muc');
    expect(ACCOUNT_STRINGS.lastParent(2, 3)[1]).toBe('vaš račun, 2 otroška profila in 3 psi,');
    expect(ACCOUNT_STRINGS.lastParent(2, 3, 3)[1]).toBe('vaš račun, 2 otroška profila in 3 muce,');
    expect(ACCOUNT_STRINGS.lastParent(1, 3, 1)[1]).toBe('vaš račun, 1 otroški profil, 2 psa in 1 muca,');
  });

  it('counts cats by species, or by a cat breed when the species is missing', () => {
    expect(catCount([{ species: 'dog' }, { species: 'cat' }, { breed_type: 'maine_coon' }, { breed_type: 'unknown_new' }])).toBe(2);
  });

  it('status, reasons and timeline follow the pet shown', () => {
    expect(petStatusText('ill', 'dog')).toBe('Kuža je pri veterinarju');
    expect(petStatusText('ill', 'cat')).toBe('Muca je pri veterinarju');
    expect(petStatusText('inactive', 'cat')).toBe('Muca ni aktivna');
    expect(petStatusText('hard_stopped', 'cat')).toBe('Igra je ustavljena (hard stop)');
    expect(reasonText('fell_ill_today', 0)).toBe('Kuža je danes zbolel in je pri veterinarju.');
    expect(reasonText('fell_ill_today', 0, 'cat')).toBe('Muca je danes zbolela in je pri veterinarju.');
    expect(reasonText('game_over', 0, 'cat')).toBe('Muca je bila odvzeta — igra je končana.');
    expect(activityText({ activity_type: 'fed_pet', actor_nickname: 'Maja' })).toBe('Maja nahranil(a) kužka');
    expect(activityText({ activity_type: 'fed_pet', actor_nickname: 'Maja' }, 'cat')).toBe('Maja nahranil(a) muco');
    expect(activityText({ activity_type: 'watered_pet', actor_nickname: 'Maja' }, 'cat')).toBe('Maja nalil(a) vodo');
  });

  it('a global child switch never leaks into a parent text', () => {
    setTextSpecies('cat');
    expect(petStatusText('ill', 'dog')).toBe('Kuža je pri veterinarju');
    setTextSpecies(null);
    expect(petStatusText('ill', 'cat')).toBe('Muca je pri veterinarju');
  });

  it('card and controls texts', () => {
    expect(CHILD_CARD_STRINGS.awaitingContract('Maja')).toBe('Kuža čaka, da Maja podpiše pogodbo o odgovornosti.');
    expect(CHILD_CARD_STRINGS.awaitingContract('Maja', 'cat')).toBe('Muca čaka, da Maja podpiše pogodbo o odgovornosti.');
    expect(CHILD_CARD_STRINGS.petLabel('dog')).toBe('Kuža');
    expect(CHILD_CARD_STRINGS.petLabel('cat')).toBe('Muca');
    expect(CHILD_CARD_STRINGS.energyLabel('dog')).toBe('Gibanje');
    expect(CHILD_CARD_STRINGS.energyLabel('cat')).toBe('Igra');
    expect(PET_CONTROLS_STRINGS.confirmStop('', 'cat')).toBe(
      'Ustaviti igro? Muca se zamrzne in otrok ne more ničesar narediti, dokler igre ne nadaljujete.',
    );
    expect(PET_CONTROLS_STRINGS.confirmStop('Luka')).toBe(t('parent:petControls.confirmStopFor', { names: 'Luka' }));
    expect(PET_CONTROLS_STRINGS.confirmResumeFor('cat')).toBe('Nadaljevati igro? Muca se odmrzne in otrok lahko spet skrbi zanjo.');
  });

  it('a scratched sofa is taken to the scratching post, a chewed slipper keeps the dog wording', () => {
    expect(parentBehaviourLines(behaviour('scratching'), TZ)).toEqual(['Opraskan kavč — na praskalnik do 13:30']);
    expect(parentBehaviourLines(behaviour('chewing'), TZ)).toEqual(['Pregrizen copat — počistiti do 13:30']);
  });

  it('profile lines and growth labels', () => {
    expect(stageLine(profile)).toBe('Mladiček · 3 mesece');
    expect(stageLine(profile, 'cat')).toBe('Mucek · 3 mesece');
    expect(originLine(profile, 'cat')).toBe('Posvojena iz zavetišča ali od znancev');
    expect(originLine(profile)).toBe('Posvojen iz zavetišča');
    expect(nextStageLine(profile, 'parent', 'cat')).toBe('Od 24. 11. 2026 mlada mačka');
    expect(nextStageLine(profile, 'parent')).toBe('Od 24. 11. 2026 mlad pes');
    const album: GrowthAlbum = {
      petId: 1,
      entries: [
        { generation: 1, stage: 'puppy', ageMonths: 2, takenAt: null, url: 'https://x/1.jpg', isCurrent: false },
        { generation: 2, stage: 'young', ageMonths: 12, takenAt: null, url: 'https://x/2.jpg', isCurrent: true },
      ],
      expiresAt: null,
    };
    expect(growthViewerItems(album, TZ, 'cat').map((i) => i.stageLabel)).toEqual(['Mucek', 'Mlada mačka']);
    expect(growthViewerItems(album, TZ).map((i) => i.stageLabel)).toEqual(['Mladiček', 'Mlad pes']);
  });

  it('the child app reads the profile stages of a cat through its text species', () => {
    setTextSpecies('cat');
    expect(stageLine(profile)).toBe('Mucek · 3 mesece');
    expect(nextStageLine(profile, 'child')).toBe('24. 11. 2026 postane mlada mačka');
    expect(t('push:push.channels.default')).toBe('Opomniki za muco');
    setTextSpecies(null);
    expect(t('push:push.channels.default')).toBe('Opomniki za kužo');
  });
});

describe('English parent texts per species', () => {
  beforeEach(async () => {
    await switchLanguage('en');
  });

  it('counts and status', () => {
    expect(DASHBOARD_STRINGS.familySubtitle(2, 1)).toBe('2 children · 1 dog');
    expect(DASHBOARD_STRINGS.familySubtitle(2, 2, 1)).toBe('2 children · 1 dog · 1 cat');
    expect(ACCOUNT_STRINGS.lastParent(1, 2, 1)[1]).toBe('your account, 1 child profile, 1 dog and 1 cat,');
    expect(ACCOUNT_STRINGS.lastParent(1, 2, 2)[1]).toBe('your account, 1 child profile and 2 cats,');
    expect(petStatusText('awaiting_contract', 'cat')).toBe('The cat is waiting for the contract to be signed');
    expect(parentBehaviourLines(behaviour('scratching'), TZ)).toEqual(['Scratched sofa — to the scratching post by 13:30']);
  });
});
