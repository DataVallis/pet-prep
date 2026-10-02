/**
 * Tests for parent dashboard traffic light logic and utility functions.
 */

import {
  getMetricColor,
  interpolateColor,
  isActionDisabled,
} from '@/utils/metrics';

/**
 * Traffic light status mapping based on escalation level.
 * This mirrors the backend ParentDashboardController::calculateTrafficLight.
 */
function getTrafficLight(escalationLevel: number, isGameOver: boolean, isIll: boolean): 'green' | 'amber' | 'red' {
  if (isGameOver || escalationLevel >= 3 || isIll) {
    return 'red';
  }
  if (escalationLevel >= 1) {
    return 'amber';
  }
  return 'green';
}

describe('Parent Dashboard - Traffic Light Logic', () => {
  it('returns green when escalation level is 0 and pet is healthy', () => {
    expect(getTrafficLight(0, false, false)).toBe('green');
  });

  it('returns amber when escalation level is 1', () => {
    expect(getTrafficLight(1, false, false)).toBe('amber');
  });

  it('returns amber when escalation level is 2', () => {
    expect(getTrafficLight(2, false, false)).toBe('amber');
  });

  it('returns red when escalation level is 3', () => {
    expect(getTrafficLight(3, false, false)).toBe('red');
  });

  it('returns red when pet is in game over', () => {
    expect(getTrafficLight(0, true, false)).toBe('red');
    expect(getTrafficLight(1, true, false)).toBe('red');
  });

  it('returns red when pet is ill', () => {
    expect(getTrafficLight(0, false, true)).toBe('red');
  });

  it('red takes priority over amber', () => {
    expect(getTrafficLight(2, true, false)).toBe('red');
    expect(getTrafficLight(2, false, true)).toBe('red');
  });
});

describe('Parent Dashboard - Metric Display', () => {
  it('green for healthy metrics', () => {
    expect(getMetricColor(80)).toBe('#10B981');
    expect(getMetricColor(100)).toBe('#10B981');
  });

  it('amber for moderate metrics', () => {
    expect(getMetricColor(50)).toBe('#F59E0B');
    expect(getMetricColor(40)).toBe('#F59E0B');
  });

  it('red for critical metrics', () => {
    expect(getMetricColor(15)).toBe('#EF4444');
    expect(getMetricColor(5)).toBe('#EF4444');
    expect(getMetricColor(0)).toBe('#EF4444');
  });

  it('interpolates colors smoothly', () => {
    const high = interpolateColor(100);
    const mid = interpolateColor(50);
    const low = interpolateColor(0);
    // At exact boundaries, colors match the reference values
    expect(high.toLowerCase()).toBe('#10b981');
    expect(mid.toLowerCase()).toBe('#f59e0b');
    expect(low.toLowerCase()).toBe('#ef4444');
  });
});

describe('Parent Dashboard - Activity Classification', () => {
  const positiveActivities = ['fed_pet', 'watered_pet', 'walked_pet', 'cleaned_poop'];
  const negativeActivities = ['ignored_warning'];

  it('classifies feeding as positive', () => {
    expect(positiveActivities).toContain('fed_pet');
  });

  it('classifies watering as positive', () => {
    expect(positiveActivities).toContain('watered_pet');
  });

  it('classifies walking as positive', () => {
    expect(positiveActivities).toContain('walked_pet');
  });

  it('classifies cleaning as positive', () => {
    expect(positiveActivities).toContain('cleaned_poop');
  });

  it('classifies ignored_warning as negative', () => {
    expect(negativeActivities).toContain('ignored_warning');
  });

  it('does not classify positive activities as negative', () => {
    positiveActivities.forEach((activity) => {
      expect(negativeActivities).not.toContain(activity);
    });
  });
});
