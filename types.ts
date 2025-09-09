// This file is for TypeScript type definitions.

export interface Category {
  id: string;
  name: string;
  icon: React.ComponentType<{ className?: string }>;
}

export interface Listing {
  id: string;
  title: string;
  price: number;
  location: string;
  image: string;
  category: 'Properties' | 'Cars' | 'Rentals' | 'Services';
}
