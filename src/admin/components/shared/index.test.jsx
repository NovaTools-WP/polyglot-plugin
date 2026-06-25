import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import {
  LoadingSpinner,
  PolyglotNav,
  ErrorState,
  ProgressBar,
  StatCard,
  Toast,
} from "./index";

describe("LoadingSpinner", () => {
  it("renders a spinner element", () => {
    const { container } = render(<LoadingSpinner />);
    // The spinner is the only animated element rendered.
    expect(container.querySelector(".animate-spin")).not.toBeNull();
  });

  it("merges the supplied className", () => {
    const { container } = render(<LoadingSpinner className="mt-4" />);
    expect(container.firstChild.className).toContain("mt-4");
  });
});

describe("ProgressBar", () => {
  it("computes the percentage from value/max", () => {
    const { container } = render(<ProgressBar value={25} max={100} />);
    const bar = container.querySelector(".bg-blue-600");
    expect(bar.style.width).toBe("25%");
  });

  it("clamps the fill to 100% when value exceeds max", () => {
    const { container } = render(<ProgressBar value={150} max={100} />);
    expect(container.querySelector(".bg-blue-600").style.width).toBe("100%");
  });

  it("renders 0% when max is zero", () => {
    const { container } = render(<ProgressBar value={10} max={0} />);
    expect(container.querySelector(".bg-blue-600").style.width).toBe("0%");
  });
});

describe("ErrorState", () => {
  it("renders the message", () => {
    render(<ErrorState message="Something went wrong" />);
    expect(screen.getByText("Something went wrong")).toBeInTheDocument();
  });

  it("does not render a retry button when no handler is given", () => {
    render(<ErrorState message="fail" />);
    expect(screen.queryByText("Try Again")).toBeNull();
  });

  it("calls onRetry when the retry button is clicked", () => {
    const onRetry = vi.fn();
    render(<ErrorState message="fail" onRetry={onRetry} />);
    fireEvent.click(screen.getByText("Try Again"));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });
});

describe("StatCard", () => {
  it("renders title, value and subtitle", () => {
    render(
      <StatCard title="Posts" value={42} subtitle="across 3 languages" />,
    );
    expect(screen.getByText("Posts")).toBeInTheDocument();
    expect(screen.getByText("42")).toBeInTheDocument();
    expect(screen.getByText("across 3 languages")).toBeInTheDocument();
  });

  it("omits the subtitle when none is provided", () => {
    render(<StatCard title="Posts" value={42} />);
    expect(screen.getByText("Posts")).toBeInTheDocument();
    expect(screen.queryByText("across 3 languages")).toBeNull();
  });
});

describe("Toast", () => {
  it("renders the message", () => {
    render(<Toast message="Saved" onClose={() => {}} />);
    expect(screen.getByText("Saved")).toBeInTheDocument();
  });

  it("calls onClose when the close button is clicked", () => {
    const onClose = vi.fn();
    render(<Toast message="Saved" onClose={onClose} />);
    fireEvent.click(screen.getByText("×"));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});

describe("PolyglotNav", () => {
  beforeEach(() => {
    // Reset location between tests so active-state assertions are deterministic.
    window.location.hash = "";
  });

  it("renders all navigation items", () => {
    render(<PolyglotNav />);
    expect(screen.getByText("Dashboard")).toBeInTheDocument();
    expect(screen.getByText("Languages")).toBeInTheDocument();
    expect(screen.getByText("Translations")).toBeInTheDocument();
    expect(screen.getByText("String Translation")).toBeInTheDocument();
    expect(screen.getByText("Scan")).toBeInTheDocument();
    expect(screen.getByText("Settings")).toBeInTheDocument();
    expect(screen.getByText("Import WPML")).toBeInTheDocument();
  });

  it("highlights the active section based on the URL hash", () => {
    window.location.hash = "#/polyglot/languages";
    render(<PolyglotNav />);
    const activeLink = screen.getByText("Languages").closest("a");
    expect(activeLink.className).toContain("text-blue-600");
  });
});
