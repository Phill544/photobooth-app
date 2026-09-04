import type { UploadFailureKind } from './upload';
import type { StripTemplate } from './templates';

// The whole booth flow as a pure state machine. The browser glue dispatches
// events (ticks, captures, taps) and renders whatever screen comes back.

export type FlowState =
    | { screen: 'start' }
    | { screen: 'countdown'; shotIndex: number; secondsLeft: number }
    | { screen: 'flash'; shotIndex: number }
    | { screen: 'review' }
    | { screen: 'customise' }
    | { screen: 'uploading'; uploaded: number; total: number }
    | { screen: 'uploadFailed'; total: number; uploaded: number; reason: UploadFailureKind }
    | { screen: 'done' }
    | { screen: 'cameraLost'; shotIndex: number };

export type FlowEvent =
    | { type: 'start' }
    | { type: 'customise' }
    | { type: 'tick' }
    | { type: 'shotCaptured' }
    | { type: 'retake' }
    | { type: 'share' }
    | { type: 'photoUploaded' }
    | { type: 'uploadFailed'; reason: UploadFailureKind }
    | { type: 'retryUpload' }
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
            if (event.type === 'customise') return { screen: 'customise' };
            return state;

        case 'customise':
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
            if (event.type === 'uploadFailed') {
                // The count comes along: the strip is queued first, so one landed
                // file already means the strip itself is in the album, and the
                // screen must not tell the guest otherwise.
                return {
                    screen: 'uploadFailed',
                    total: state.total,
                    uploaded: state.uploaded,
                    reason: event.reason,
                };
            }
            if (event.type !== 'photoUploaded') return state;
            if (state.uploaded + 1 < state.total) return { ...state, uploaded: state.uploaded + 1 };
            return { screen: 'done' };

        case 'uploadFailed':
            // Re-run from the start; already-sent slots dedup on the server.
            if (event.type === 'retryUpload') return { screen: 'uploading', uploaded: 0, total: state.total };
            return state;

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
