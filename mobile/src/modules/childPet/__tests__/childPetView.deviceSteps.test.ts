/** M5-T12 QA M1: `steps.my_device_steps_today` (device part) vs `my_steps_today` (display). */
import { normalizeChildState } from '@/modules/childPet/childPetView';
import { makeLiveChildState } from '@/test-utils/fixtures';

describe('normalizeChildState — my_device_steps_today', () => {
  it('reads the device part separately from the total', () => {
    const view = normalizeChildState(makeLiveChildState({ steps: { my_steps_today: 3000, my_device_steps_today: 500 } }));
    expect(view.steps.my_steps_today).toBe(3000);
    expect(view.steps.my_device_steps_today).toBe(500);
  });

  it('an older server without the field → the total (no parent-logged walks there)', () => {
    const view = normalizeChildState(makeLiveChildState({ steps: { my_steps_today: 1200 } }));
    expect(view.steps.my_device_steps_today).toBe(1200);
  });
});
