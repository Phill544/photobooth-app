import type { StripTemplate } from './templates';

// The whole booth flow as a pure state machine. The browser glue dispatches
// events (ticks, captures, taps) and renders whatever screen comes back.

export type FlowState =
    | { screen: 'start' }
    | { screen: 'countdown'; shotIndex: number; secondsLeft: number }
    | { screen: 'flash'; shotIndex: number }
    | { screen: 'review' }
    | { screen: 'uploading'; uploaded: number; total: number }
    | { screen: 'done' }
    | { screen: 'cameraLost'; shotIndex: number };

export type FlowEvent =
    | { type: 'start' }
    | { type: 'tick' }
    | { type: 'shotCaptured' }
    | { type: 'retake' }
    | { type: 'share' }
    | { type: 'photoUploaded' }
    | { type: 'cameraLost' }
    | { type: 'cameraBack' };

export const COUNTDOWN_SECONDS = 3;

export function nextState(state: FlowState, event: FlowEvent, template: StripTemplate): FlowState {
    if (event.type === 'cameraLost') {
        if (state.screen === 'countdown' || state.screen === 'flash') {
            return { screen: 'cameraLost', shotIndex: state.shotIndex };
        }
        return state;
    }

    switch (state.screen) {
        case 'start':
            if (event.type === 'start') return countdownFor(0);
            return state;

        case 'countdown':
            if (event.type !== 'tick') return state;
            if (state.secondsLeft > 1) return { ...state, secondsLeft: state.secondsLeft - 1 };
            return { screen: 'flash', shotIndex: state.shotIndex };

        case 'flash':
            if (event.type !== 'shotCaptured') return state;
            if (state.shotIndex + 1 < template.cellCount) return countdownFor(state.shotIndex + 1);
            return { screen: 'review' };

        case 'review':
            if (event.type === 'retake') return countdownFor(0);
            if (event.type === 'share') {
                return { screen: 'uploading', uploaded: 0, total: template.cellCount + 1 };
            }
            return state;

        case 'uploading':
            if (event.type !== 'photoUploaded') return state;
            if (state.uploaded + 1 < state.total) return { ...state, uploaded: state.uploaded + 1 };
            return { screen: 'done' };

        case 'done':
            if (event.type === 'retake') return countdownFor(0);
            return state;

        case 'cameraLost':
            if (event.type === 'cameraBack') return countdownFor(state.shotIndex);
            return state;
    }
}

function countdownFor(shotIndex: number): FlowState {
    return { screen: 'countdown', shotIndex, secondsLeft: COUNTDOWN_SECONDS };
}
