/**
 * Bundled translations (M1-18). One JSON file per namespace and language; English (`en`)
 * is the reference — every language has exactly the same keys (`__tests__/parity.test.ts`).
 * Add a language: copy `locales/en`, translate, register it here and in `language.ts`.
 */

import en_common from './locales/en/common.json';
import en_auth from './locales/en/auth.json';
import en_child from './locales/en/child.json';
import en_behaviour from './locales/en/behaviour.json';
import en_training from './locales/en/training.json';
import en_contract from './locales/en/contract.json';
import en_parent from './locales/en/parent.json';
import en_family from './locales/en/family.json';
import en_account from './locales/en/account.json';
import en_pet from './locales/en/pet.json';
import en_push from './locales/en/push.json';
import en_paywall from './locales/en/paywall.json';
import en_purchases from './locales/en/purchases.json';
import en_play from './locales/en/play.json';
import en_cat from './locales/en/cat.json';
import en_certificate from './locales/en/certificate.json';
import en_socialisation from './locales/en/socialisation.json';
import en_facts from './locales/en/facts.json';
import en_careGuide from './locales/en/careGuide.json';
import en_preparation from './locales/en/preparation.json';
import en_birthday from './locales/en/birthday.json';
import en_careTasks from './locales/en/careTasks.json';
import en_wishes from './locales/en/wishes.json';
import sl_common from './locales/sl/common.json';
import sl_auth from './locales/sl/auth.json';
import sl_child from './locales/sl/child.json';
import sl_behaviour from './locales/sl/behaviour.json';
import sl_training from './locales/sl/training.json';
import sl_contract from './locales/sl/contract.json';
import sl_parent from './locales/sl/parent.json';
import sl_family from './locales/sl/family.json';
import sl_account from './locales/sl/account.json';
import sl_pet from './locales/sl/pet.json';
import sl_push from './locales/sl/push.json';
import sl_paywall from './locales/sl/paywall.json';
import sl_purchases from './locales/sl/purchases.json';
import sl_play from './locales/sl/play.json';
import sl_cat from './locales/sl/cat.json';
import sl_certificate from './locales/sl/certificate.json';
import sl_socialisation from './locales/sl/socialisation.json';
import sl_facts from './locales/sl/facts.json';
import sl_careGuide from './locales/sl/careGuide.json';
import sl_preparation from './locales/sl/preparation.json';
import sl_birthday from './locales/sl/birthday.json';
import sl_careTasks from './locales/sl/careTasks.json';
import sl_wishes from './locales/sl/wishes.json';

export const NAMESPACES = ['common', 'auth', 'child', 'behaviour', 'training', 'contract', 'parent', 'family', 'account', 'pet', 'push', 'paywall', 'purchases', 'play', 'cat', 'certificate', 'socialisation', 'facts', 'careGuide', 'preparation', 'birthday', 'careTasks', 'wishes'] as const;
export type Namespace = (typeof NAMESPACES)[number];

export const en = {
  common: en_common,
  auth: en_auth,
  child: en_child,
  behaviour: en_behaviour,
  training: en_training,
  contract: en_contract,
  parent: en_parent,
  family: en_family,
  account: en_account,
  pet: en_pet,
  push: en_push,
  paywall: en_paywall,
  purchases: en_purchases,
  play: en_play,
  cat: en_cat,
  certificate: en_certificate,
  socialisation: en_socialisation,
  facts: en_facts,
  careGuide: en_careGuide,
  preparation: en_preparation,
  birthday: en_birthday,
  careTasks: en_careTasks,
  wishes: en_wishes,
} as const;

/** Plural suffixes differ per language (sl: _one/_two/_few/_other), so key parity is a test, not a type. */
const sl = {
  common: sl_common,
  auth: sl_auth,
  child: sl_child,
  behaviour: sl_behaviour,
  training: sl_training,
  contract: sl_contract,
  parent: sl_parent,
  family: sl_family,
  account: sl_account,
  pet: sl_pet,
  push: sl_push,
  paywall: sl_paywall,
  purchases: sl_purchases,
  play: sl_play,
  cat: sl_cat,
  certificate: sl_certificate,
  socialisation: sl_socialisation,
  facts: sl_facts,
  careGuide: sl_careGuide,
  preparation: sl_preparation,
  birthday: sl_birthday,
  careTasks: sl_careTasks,
  wishes: sl_wishes,
};

export const resources = { en, sl } as const;
export type Resources = typeof en;
