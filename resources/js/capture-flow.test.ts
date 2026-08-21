import { describe, expect, it } from 'vitest';
import { COUNTDOWN_SECONDS, nextState, type FlowEvent, type FlowState } from './capture-flow';
import type { StripTemplate } from './templates';

function template(cellCount: number): StripTemplate {
    return {
        name: 'test',
        cellCount,
        cellWidth: 600,
        cellHeight: 450,
        padding: 24,
        footerHeight: 96,
        background: '#111',
        textColor: '#fff',
    };
}

function walk(state: FlowState, events: FlowEvent[], cellCount = 3): FlowState {
    return events.reduce((current, event) => nextState(current, event, template(cellCount)), state);
}

describe('the happy path', () => {
    it('starts counting down for the first shot', () => {
        const state = walk({ screen: 'start' }, [{ type: 'start' }]);

        expect(state).toEqual({ screen: 'countdown', shotIndex: 0, secondsLeft: COUNTDOWN_SECONDS });
    });

    it('counts down to the flash', () => {
        const state = walk({ screen: 'start' }, [
            { type: 'start' },
            { type: 'tick' },
            { type: 'tick' },
            { type: 'tick' },
        ]);

        expect(state).toEqual({ screen: 'flash', shotIndex: 0 });
    });

    it('moves to the next shot after a capture', () => {
        const state = walk({ screen: 'flash', shotIndex: 0 }, [{ type: 'shotCaptured' }]);

        expect(state).toEqual({ screen: 'countdown', shotIndex: 1, secondsLeft: COUNTDOWN_SECONDS });
    });

    it('reaches review after the last shot of the template', () => {
        expect(walk({ screen: 'flash', shotIndex: 1 }, [{ type: 'shotCaptured' }], 2))
            .toEqual({ screen: 'review' });
        expect(walk({ screen: 'flash', shotIndex: 3 }, [{ type: 'shotCaptured' }], 4))
            .toEqual({ screen: 'review' });
    });

    it('uploads every shot plus the strip after consent', () => {
        expect(walk({ screen: 'review' }, [{ type: 'share' }], 2))
            .toEqual({ screen: 'uploading', uploaded: 0, total: 3 });
        expect(walk({ screen: 'review' }, [{ type: 'share' }], 4))
            .toEqual({ screen: 'uploading', uploaded: 0, total: 5 });
    });

    it('finishes once every file is uploaded', () => {
        const uploading: FlowState = { screen: 'uploading', uploaded: 0, total: 3 };

        expect(walk(uploading, [{ type: 'photoUploaded' }]))
            .toEqual({ screen: 'uploading', uploaded: 1, total: 3 });
        expect(walk(uploading, [{ type: 'photoUploaded' }, { type: 'photoUploaded' }, { type: 'photoUploaded' }]))
            .toEqual({ screen: 'done' });
    });
});

describe('retakes', () => {
    it('restarts the whole set from review', () => {
        expect(walk({ screen: 'review' }, [{ type: 'retake' }]))
            .toEqual({ screen: 'countdown', shotIndex: 0, secondsLeft: COUNTDOWN_SECONDS });
    });

    it('starts a fresh set from done', () => {
        expect(walk({ screen: 'done' }, [{ type: 'retake' }]))
            .toEqual({ screen: 'countdown', shotIndex: 0, secondsLeft: COUNTDOWN_SECONDS });
    });
});

describe('losing the camera mid-session', () => {
    it('parks on the camera-lost screen during a countdown', () => {
        const state = walk({ screen: 'countdown', shotIndex: 1, secondsLeft: 2 }, [{ type: 'cameraLost' }]);

        expect(state).toEqual({ screen: 'cameraLost', shotIndex: 1 });
    });

    it('restarts the countdown for the same shot when the camera returns', () => {
        const state = walk({ screen: 'cameraLost', shotIndex: 1 }, [{ type: 'cameraBack' }]);

        expect(state).toEqual({ screen: 'countdown', shotIndex: 1, secondsLeft: COUNTDOWN_SECONDS });
    });

    it('ignores camera loss on screens that do not need the camera', () => {
        const review: FlowState = { screen: 'review' };
        const uploading: FlowState = { screen: 'uploading', uploaded: 1, total: 4 };

        expect(walk(review, [{ type: 'cameraLost' }])).toEqual(review);
        expect(walk(uploading, [{ type: 'cameraLost' }])).toEqual(uploading);
    });

    it('ignores stale ticks while the camera is lost', () => {
        const lost: FlowState = { screen: 'cameraLost', shotIndex: 0 };

        expect(walk(lost, [{ type: 'tick' }])).toEqual(lost);
    });
});
