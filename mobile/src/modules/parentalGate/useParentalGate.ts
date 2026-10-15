/**
 * State of one parental gate (see `parentalGate.ts`). Usage:
 *
 *   const gate = useParentalGate({ onPass: () => void logout() });
 *   <Pressable onPress={gate.open} />
 *   <ParentalGate {...gate.props} />
 *
 * Reused by the profile switch (planned): only `onPass` differs.
 */

import { useCallback, useRef, useState } from 'react';

import { MAX_ATTEMPTS, isCorrectAnswer, makeQuestion, type GateQuestion } from './parentalGate';

export type GateSubmitResult = 'passed' | 'wrong' | 'closed';

export interface ParentalGateProps {
  visible: boolean;
  question: GateQuestion;
  /** The last answer was wrong (show the adult-facing "try again" text). */
  wrong: boolean;
  onSubmit: (typed: string) => GateSubmitResult;
  onCancel: () => void;
}

interface Options {
  onPass: () => void;
  /** Tests: deterministic questions. */
  random?: () => number;
}

export function useParentalGate({ onPass, random = Math.random }: Options) {
  const [visible, setVisible] = useState(false);
  const [question, setQuestion] = useState<GateQuestion>(() => makeQuestion(random));
  const [wrong, setWrong] = useState(false);
  const attempts = useRef(0);
  // The latest question, for the "never the same one twice" rule inside callbacks.
  const current = useRef(question);

  const ask = useCallback(() => {
    const next = makeQuestion(random, current.current);
    current.current = next;
    setQuestion(next);
  }, [random]);

  const open = useCallback(() => {
    attempts.current = 0;
    setWrong(false);
    ask();
    setVisible(true);
  }, [ask]);

  const close = useCallback(() => {
    setVisible(false);
    setWrong(false);
  }, []);

  const onSubmit = useCallback(
    (typed: string): GateSubmitResult => {
      if (isCorrectAnswer(current.current, typed)) {
        close();
        onPass();
        return 'passed';
      }
      attempts.current += 1;
      if (attempts.current >= MAX_ATTEMPTS) {
        close();
        return 'closed';
      }
      setWrong(true);
      ask();
      return 'wrong';
    },
    [ask, close, onPass],
  );

  const props: ParentalGateProps = { visible, question, wrong, onSubmit, onCancel: close };
  return { open, close, props };
}
