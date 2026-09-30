using Xunit;

// The tests share a single container with shared repo/cache state, so they must run serially.
[assembly: CollectionBehavior(DisableTestParallelization = true)]
