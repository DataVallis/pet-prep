/**
 * Parental gate (David 2026-10-14): random multiplication, a new question every time and after
 * every wrong answer, 3 wrong answers close it, only a correct one passes.
 */
import { fireEvent, render, screen } from '@testing-library/react-native';

import ParentalGate, { PARENTAL_GATE_STRINGS as S } from '@/components/ParentalGate';
import ParentExitLink from '@/components/ParentExitLink';
import { MAX_ATTEMPTS, MAX_FACTOR, MIN_FACTOR, isCorrectAnswer, makeQuestion } from '@/modules/parentalGate/parentalGate';
import { useParentalGate } from '@/modules/parentalGate/useParentalGate';
import { Pressable } from 'react-native';

/** Deterministic source cycling through the given [0,1) values. */
function sequence(...values: number[]) {
  let i = 0;
  return () => values[i++ % values.length];
}

/** Read "Koliko je 7 × 8?" back into its product. */
function currentProduct(): number {
  const text = String(screen.getByTestId('parental-gate-question').props.children);
  const [a, b] = (text.match(/\d+/g) ?? []).map(Number);
  return a * b;
}

function answer(value: string) {
  fireEvent.changeText(screen.getByTestId('parental-gate-input'), value);
  fireEvent.press(screen.getByTestId('parental-gate-submit'));
}

function Harness({ onPass, random }: { onPass: () => void; random?: () => number }) {
  const gate = useParentalGate({ onPass, random });
  return (
    <>
      <Pressable testID="open" onPress={gate.open} />
      <ParentalGate {...gate.props} />
    </>
  );
}

describe('parentalGate logic', () => {
  it('factors are 4–9 (two-digit products), never the same question twice in a row', () => {
    for (let i = 0; i < 200; i += 1) {
      const q = makeQuestion();
      expect(q.a).toBeGreaterThanOrEqual(MIN_FACTOR);
      expect(q.b).toBeLessThanOrEqual(MAX_FACTOR);
      expect(q.a * q.b).toBeGreaterThanOrEqual(10);
    }
    const first = makeQuestion(sequence(0.5, 0.5));
    const next = makeQuestion(sequence(0.5, 0.5, 0.9, 0.1), first);
    expect(next).not.toEqual(first);
  });

  it('accepts only the product (spaces forgiven, no other text)', () => {
    expect(isCorrectAnswer({ a: 7, b: 8 }, '56')).toBe(true);
    expect(isCorrectAnswer({ a: 7, b: 8 }, ' 56 ')).toBe(true);
    expect(isCorrectAnswer({ a: 7, b: 8 }, '65')).toBe(false);
    expect(isCorrectAnswer({ a: 7, b: 8 }, '')).toBe(false);
    expect(isCorrectAnswer({ a: 7, b: 8 }, '56.0')).toBe(false);
  });
});

describe('ParentalGate + useParentalGate', () => {
  it('a correct answer passes and closes', () => {
    const onPass = jest.fn();
    render(<Harness onPass={onPass} />);
    expect(screen.queryByTestId('parental-gate')).toBeNull();

    fireEvent.press(screen.getByTestId('open'));
    expect(screen.getByText(S.title)).toBeTruthy();
    answer(String(currentProduct()));

    expect(onPass).toHaveBeenCalledTimes(1);
    expect(screen.queryByTestId('parental-gate')).toBeNull();
  });

  it('a wrong answer: adult-facing message, a NEW question, no pass', () => {
    const onPass = jest.fn();
    render(<Harness onPass={onPass} />);
    fireEvent.press(screen.getByTestId('open'));
    const before = screen.getByTestId('parental-gate-question').props.children;

    answer(String(currentProduct() + 1));

    expect(onPass).not.toHaveBeenCalled();
    expect(screen.getByTestId('parental-gate-wrong')).toHaveTextContent(S.wrong);
    expect(screen.getByTestId('parental-gate-question').props.children).not.toEqual(before);
    expect(screen.getByTestId('parental-gate-input').props.value).toBe('');
  });

  it(`${MAX_ATTEMPTS} wrong answers close the gate; reopening starts fresh with a new question`, () => {
    const onPass = jest.fn();
    render(<Harness onPass={onPass} />);
    fireEvent.press(screen.getByTestId('open'));
    for (let i = 0; i < MAX_ATTEMPTS; i += 1) answer('1');

    expect(screen.queryByTestId('parental-gate')).toBeNull();
    expect(onPass).not.toHaveBeenCalled();

    fireEvent.press(screen.getByTestId('open'));
    expect(screen.queryByTestId('parental-gate-wrong')).toBeNull();
    answer(String(currentProduct()));
    expect(onPass).toHaveBeenCalledTimes(1);
  });

  it('each opening asks a new question', () => {
    render(<Harness onPass={jest.fn()} random={sequence(0.1, 0.2, 0.8, 0.9)} />);
    fireEvent.press(screen.getByTestId('open'));
    const first = screen.getByTestId('parental-gate-question').props.children;
    fireEvent.press(screen.getByTestId('parental-gate-cancel'));
    fireEvent.press(screen.getByTestId('open'));
    expect(screen.getByTestId('parental-gate-question').props.children).not.toEqual(first);
  });

  it('the input keeps digits only', () => {
    render(<Harness onPass={jest.fn()} />);
    fireEvent.press(screen.getByTestId('open'));
    fireEvent.changeText(screen.getByTestId('parental-gate-input'), '5a6!');
    expect(screen.getByTestId('parental-gate-input').props.value).toBe('56');
  });

  it('ParentExitLink: "Starš?" opens the gate; the pass action runs only after a correct answer', () => {
    const onPass = jest.fn();
    render(<ParentExitLink onPass={onPass} />);
    expect(screen.getByText(S.link)).toBeTruthy();
    fireEvent.press(screen.getByTestId('parent-exit-link'));
    answer('0');
    expect(onPass).not.toHaveBeenCalled();
    answer(String(currentProduct()));
    expect(onPass).toHaveBeenCalledTimes(1);
  });
});
