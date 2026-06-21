import { Component } from '@wordpress/element';

/**
 * Minimal React error boundary. Without one, a throwing child (e.g. ListView)
 * propagates up and unmounts the ENTIRE editor tree — the whole Block Editor
 * disappears (blank screen). This contains the failure to the boundary and
 * renders an optional fallback instead, so the rest of the editor survives.
 */
export default class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false };
	}

	static getDerivedStateFromError() {
		return { hasError: true };
	}

	render() {
		if ( this.state.hasError ) {
			return this.props.fallback || null;
		}
		return this.props.children;
	}
}
